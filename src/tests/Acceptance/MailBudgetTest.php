<?php

namespace Tests\Acceptance;

use App\Exceptions\MailBudgetExceeded;
use App\Models\User;
use App\Services\AccountNotifications;
use App\Services\MailBudget;
use App\Services\Monitoring;
use App\Services\NotificationOutbox;
use Illuminate\Database\QueryException;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Symfony\Component\Mime\Email;
use Symfony\Component\Process\Process;
use Tests\Support\BrandFixture;

class MailBudgetTest extends AcceptanceCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->control->table('mail_budget_attempts')->delete();
        $this->policy(false, 250, 200, 50);
        config(['notifications.workspace_per_minute' => 0, 'notifications.global_per_minute' => 0, 'notifications.account_per_minute' => 0]);
    }

    protected function tearDown(): void
    {
        $this->policy(false, 250, 200, 50);
        DB::purge('mail_budget');
        parent::tearDown();
    }

    private function policy(bool $enabled, int $total = 3, int $business = 2, int $account = 1): void
    {
        $this->control->table('mail_budget_policy')->where('singleton', true)->update([
            'enabled' => $enabled, 'total_limit' => $total, 'business_limit' => $business, 'account_limit' => $account,
        ]);
    }

    private function reserve(string $category): array
    {
        return app(MailBudget::class)->reserve($category);
    }

    private function snapshot(): array
    {
        return json_decode(DB::selectOne('SELECT app_mail_budget_snapshot() AS data')->data, true, flags: JSON_THROW_ON_ERROR);
    }

    /** Keep Laravel's real MessageSending event while replacing only the final network transport. */
    private function capturedSmtp(): ArrayTransport
    {
        config(['mail.default' => 'smtp', 'mail.from.address' => 'synthetic@example.test']);
        Mail::purge('smtp');
        $transport = new ArrayTransport;
        Mail::mailer('smtp')->setSymfonyTransport($transport);

        return $transport;
    }

    public function test_default_is_inactive_and_runtime_cannot_read_or_edit_policy_or_consumption(): void
    {
        $this->assertSame('disabled', $this->reserve('account')['status']);
        $this->assertSame(0, $this->snapshot()['rolling_total']);
        foreach (['SELECT * FROM mail_budget_policy', 'DELETE FROM mail_budget_attempts', 'UPDATE mail_budget_policy SET enabled=true'] as $query) {
            try {
                DB::statement($query);
                $this->fail('Runtime budget access must be function-only.');
            } catch (QueryException $error) {
                $this->assertSame('42501', $error->errorInfo[0]);
            }
        }
        $this->artisan('mail:budget-policy', ['--enable' => true, '--operator' => 'test', '--reason' => 'Synthetic test'])->assertExitCode(1);
    }

    public function test_allocations_do_not_refund_on_outer_rollback_and_receipts_are_single_use(): void
    {
        $this->policy(true);
        DB::beginTransaction();
        $first = $this->reserve('business');
        DB::rollBack();
        $this->assertSame('reserved', $first['status']);
        $this->assertSame('reserved', $this->reserve('business')['status']);
        $this->assertSame('limited', $this->reserve('business')['status']);
        $this->assertSame('reserved', $this->reserve('account')['status']);
        $this->assertSame('limited', $this->reserve('account')['status']);
        $this->assertSame(3, $this->snapshot()['rolling_total']);
        $this->assertTrue(DB::selectOne('SELECT app_mail_budget_consume(?::uuid,1) AS ok', [$first['receipt']])->ok);
        $this->assertFalse(DB::selectOne('SELECT app_mail_budget_consume(?::uuid,1) AS ok', [$first['receipt']])->ok);
        $this->assertSame(3, $this->snapshot()['rolling_total']);
        $this->assertSame('limited', app(MailBudget::class)->reserve('business', 2)['status']);
    }

    public function test_rolling_limit_prevents_a_midnight_reset_and_releases_only_expired_consumption(): void
    {
        $this->policy(true, 2, 1, 1);
        foreach (['business', 'account'] as $category) {
            $this->control->table('mail_budget_attempts')->insert(['id' => (string) Str::uuid(), 'category' => $category, 'recipients' => 1,
                'reserved_at' => DB::raw("(date_trunc('day',clock_timestamp() AT TIME ZONE 'UTC') AT TIME ZONE 'UTC')-interval '1 microsecond'")]);
        }
        $this->assertSame(0, $this->snapshot()['utc_day_total']);
        $this->assertSame(2, $this->snapshot()['rolling_total']);
        $this->assertSame('limited', $this->reserve('business')['status']);
        // Receipt admission can occur minutes after reservation, including across midnight.
        $this->control->table('mail_budget_attempts')->update(['reserved_at' => now()->subHours(24)->subMinute(), 'consumed_at' => now()->subHours(23)->subMinutes(55)]);
        $this->assertSame(2, $this->snapshot()['rolling_total']);
        $this->assertSame('limited', $this->reserve('business')['status']);
        $this->control->table('mail_budget_attempts')->update(['reserved_at' => now()->subHours(25), 'consumed_at' => now()->subHours(25)]);
        $this->assertSame('reserved', $this->reserve('business')['status']);
        $this->assertSame('reserved', $this->reserve('account')['status']);
    }

    public function test_concurrent_reservations_cannot_exceed_the_last_available_slot(): void
    {
        $this->policy(true, 2, 1, 1);
        $this->control->beginTransaction();
        $first = json_decode($this->control->selectOne("SELECT app_mail_budget_reserve('account',1) AS data")->data, true);
        $this->assertSame('reserved', $first['status']);
        $waiting = new Process([PHP_BINARY, 'tests/Fixtures/mail-budget-probe.php', 'account'], base_path(), ['APP_ENV' => 'testing', 'TEST_MIGRATION_DB_PASSWORD' => false]);
        $waiting->setTimeout(15);
        $waiting->start();
        try {
            $this->eventually(fn () => str_contains($waiting->getOutput(), "\n"), 'Budget probe did not connect.');
            $this->assertBlocked($waiting);
            $this->control->commit();
            $this->assertSame('limited', $this->probeResult($waiting)['status']);
            $this->assertSame(1, $this->snapshot()['rolling_total']);
        } finally {
            $waiting->stop(0);
        }
    }

    public function test_direct_mail_and_password_reset_stop_before_transport_when_account_allocation_is_full(): void
    {
        $this->policy(true);
        $transport = $this->capturedSmtp();
        Mail::raw('Synthetic account notice', fn ($mail) => $mail->to('controlled@example.test')->subject('Synthetic'));
        $this->assertCount(1, $transport->messages());
        $user = User::factory()->create();
        $known = $this->postJson('/forgot-password', ['email' => $user->email])->assertOk()->json('message');
        $unknown = $this->postJson('/forgot-password', ['email' => 'unknown-'.Str::random(8).'@example.test'])->assertOk()->json('message');
        $this->assertSame($known, $unknown);
        $this->assertCount(1, $transport->messages());
        $this->assertSame(1, $this->snapshot()['rolling_account']);
        $this->expectException(MailBudgetExceeded::class);
        Mail::raw('Must not send', fn ($mail) => $mail->to('controlled@example.test')->subject('Synthetic'));
    }

    public function test_business_mail_defers_before_sending_and_uses_its_own_allocation_once(): void
    {
        $this->policy(true, 2, 1, 1);
        $transport = $this->capturedSmtp();
        $user = User::factory()->create();
        $org = BrandFixture::create($user->id, 'Synthetic budget Brand', $this->control);
        $invite = $this->actingAs($user)->withSession(['active_org_id' => $org])->postJson('/partner-invitations',
            ['email' => 'controlled@example.test', 'send_email' => true])->assertCreated()->json('id');
        $notice = $this->control->table('notification_outbox')->where('subject_id', $invite)->first();
        $this->reserve('business');
        $windows = $this->control->table('notification_delivery_windows')->orderBy('scope')->get()->all();
        app(NotificationOutbox::class)->deliver($user->id, $org, $notice->id);
        $this->assertEquals($windows, $this->control->table('notification_delivery_windows')->orderBy('scope')->get()->all());
        $pending = $this->control->table('notification_outbox')->where('id', $notice->id)->first();
        $this->assertSame('pending', $pending->state);
        $this->assertSame('global_budget', $pending->failure_code);
        $this->assertNull($pending->started_at);
        $this->assertNotNull($pending->encrypted_payload);
        $this->assertCount(0, $transport->messages());
        $this->control->table('mail_budget_attempts')->update(['reserved_at' => now()->subHours(25)]);
        $this->control->table('notification_outbox')->where('id', $notice->id)->update(['available_at' => now()->subMinute()]);
        app(NotificationOutbox::class)->deliver($user->id, $org, $notice->id);
        $this->assertSame('sent', $this->control->table('notification_outbox')->where('id', $notice->id)->value('state'));
        $this->assertCount(1, $transport->messages());
        $this->assertSame(1, $this->snapshot()['rolling_business']);
        $this->assertSame(0, $this->snapshot()['rolling_account']);
        app(NotificationOutbox::class)->deliver($user->id, $org, $notice->id);
        $this->assertCount(1, $transport->messages());
    }

    public function test_account_outbox_daily_denial_preserves_pending_and_retry_does_not_double_charge(): void
    {
        $this->policy(true, 2, 1, 1);
        $transport = $this->capturedSmtp();
        $user = User::factory()->create();
        $request = (string) Str::uuid();
        $event = (string) Str::uuid();
        $this->control->table('brand_requests')->insert(['id' => $request, 'applicant_id' => $user->id, 'brand_name' => 'Synthetic',
            'website' => 'https://example.test', 'applicant_role' => 'Founder', 'intended_use' => 'Synthetic', 'status' => 'needs_information']);
        $this->control->table('brand_request_events')->insert(['id' => $event, 'request_id' => $request, 'version' => 1,
            'event' => 'information_requested', 'actor_id' => $user->id, 'reason' => 'Synthetic']);
        $this->control->table('account_notification_outbox')->insert(['id' => $event, 'user_id' => $user->id,
            'kind' => 'brand_request.needs_information', 'brand_request_id' => $request, 'event_id' => $event, 'request_version' => 1]);
        $this->reserve('account');
        $windows = $this->control->table('notification_delivery_windows')->orderBy('scope')->get()->all();
        app(AccountNotifications::class)->deliver($event);
        $this->assertEquals($windows, $this->control->table('notification_delivery_windows')->orderBy('scope')->get()->all());
        $this->assertSame('pending', $this->control->table('account_notification_outbox')->where('id', $event)->value('state'));
        $this->assertCount(0, $transport->messages());
        $this->control->table('mail_budget_attempts')->update(['reserved_at' => now()->subHours(25)]);
        $this->control->table('account_notification_outbox')->where('id', $event)->update(['available_at' => now()->subMinute()]);
        app(AccountNotifications::class)->deliver($event);
        $this->assertSame('sent', $this->control->table('account_notification_outbox')->where('id', $event)->value('state'));
        $this->assertCount(1, $transport->messages());
        $this->assertSame(1, $this->snapshot()['rolling_account']);
    }

    public function test_duplicate_envelope_recipients_cannot_bypass_the_single_recipient_policy(): void
    {
        $this->policy(true);
        $transport = $this->capturedSmtp();
        try {
            Mail::raw('Synthetic', fn ($mail) => $mail->to('controlled@example.test')->cc('controlled@example.test')->subject('Synthetic'));
            $this->fail('Unreviewed recipient expansion must stop before transport.');
        } catch (MailBudgetExceeded) {
            $this->assertCount(0, $transport->messages());
            $this->assertSame(0, $this->snapshot()['rolling_total']);
        }
    }

    public function test_registration_preserves_its_account_and_reports_a_paused_verification_email(): void
    {
        $this->policy(true);
        $this->reserve('account');
        $transport = $this->capturedSmtp();
        $email = 'budget-signup-'.Str::random(8).'@example.test';
        $this->postJson('/partner/register', ['name' => 'Synthetic account', 'email' => strtolower($email),
            'password' => 'SyntheticPassword123!', 'password_confirmation' => 'SyntheticPassword123!'])
            ->assertCreated()->assertJsonPath('verification_email_paused', true);
        $user = User::where('email', strtolower($email))->firstOrFail();
        $this->assertNull($user->email_verified_at);
        $this->assertAuthenticatedAs($user);
        $this->assertCount(0, $transport->messages());
        $this->postJson('/email/verification-notification')->assertStatus(503)->assertHeader('Retry-After', '3600');
        $this->assertCount(0, $transport->messages());
    }

    public function test_actual_loopback_smtp_is_charged_once_and_local_array_capture_is_not_charged(): void
    {
        $this->policy(true);
        $sink = new Process([PHP_BINARY, 'tests/Fixtures/smtp-capture.php'], base_path());
        $sink->setTimeout(15);
        $sink->start();
        try {
            $this->eventually(fn () => str_contains($sink->getOutput(), "\n"), 'Local SMTP sink did not start.');
            $port = json_decode(explode("\n", $sink->getOutput())[0], true)['port'];
            config(['mail.default' => 'smtp', 'mail.from.address' => 'sender@example.test', 'mail.mailers.smtp' => [
                'transport' => 'smtp', 'scheme' => 'smtp', 'host' => '127.0.0.1', 'port' => $port, 'timeout' => 3,
            ]]);
            Mail::purge('smtp');
            Mail::raw('Synthetic transport proof', fn ($mail) => $mail->to('controlled@example.test')->subject('Synthetic'));
            $this->assertSame(0, $sink->wait());
            $this->assertSame(1, $this->snapshot()['rolling_account']);
            config(['mail.default' => 'array']);
            Mail::raw('Local capture', fn ($mail) => $mail->to('local@example.test')->subject('Synthetic'));
            $this->assertSame(1, $this->snapshot()['rolling_account']);
        } finally {
            $sink->stop(0);
        }
    }

    public function test_usage_monitor_warns_at_eighty_percent_without_private_metadata(): void
    {
        $this->policy(true, 10, 5, 5);
        for ($i = 0; $i < 4; $i++) {
            $this->reserve('business');
        }
        $measure = app(Monitoring::class)->measure();
        $this->assertSame(['value' => 80, 'breached' => true], $measure['mail_budget_usage']);
        $this->assertArrayNotHasKey('recipients', $this->snapshot());
    }

    public function test_rollback_cannot_discard_budget_consumption(): void
    {
        $this->policy(true);
        $this->reserve('account');
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection('acceptance_control');
        try {
            $migration = require database_path('migrations/2026_11_18_000004_add_mail_daily_budget.php');
            try {
                $migration->down();
                $this->fail('Consumed budgets must survive image/schema rollback attempts.');
            } catch (\RuntimeException $error) {
                $this->assertStringContainsString('refuses to discard', $error->getMessage());
            }
        } finally {
            DB::setDefaultConnection($original);
        }
        $this->assertSame(1, $this->snapshot()['rolling_account']);
    }

    public function test_unreviewed_composite_transport_cannot_make_multiple_attempts_for_one_charge(): void
    {
        $this->policy(true);
        config(['mail.mailers.unreviewed.transport' => 'failover']);
        $message = (new Email)->from('sender@example.test')->to('controlled@example.test')->text('Synthetic');
        try {
            app(MailBudget::class)->sending(new MessageSending($message, ['mailer' => 'unreviewed']));
            $this->fail('The pilot budget must stop unsupported composite transports.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('requires the SMTP', $error->getMessage());
        }
        $this->assertSame(0, $this->control->table('mail_budget_attempts')->whereNotNull('consumed_at')->count());
    }
}
