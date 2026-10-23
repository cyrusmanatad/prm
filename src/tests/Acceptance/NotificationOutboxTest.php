<?php

namespace Tests\Acceptance;

use App\Jobs\DeliverNotification;
use App\Mail\PartnerInvitation;
use App\Models\User;
use App\Services\NotificationOutbox;
use App\Services\Workspaces;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;

class NotificationOutboxTest extends AcceptanceCase
{
    private function invitation(bool $send = true): array
    {
        $user = User::factory()->create();
        $org = app(Workspaces::class)->create($user->id, 'Private brand', 'brand');
        $recipient = 'notification-'.Str::uuid().'@example.test';
        $result = $this->actingAs($user)->withHeaders(['Host' => 'untrusted-host.example.test'])->withSession(['active_org_id' => $org])
            ->postJson('/partner-invitations', ['email' => $recipient, 'send_email' => $send])->assertCreated()->json();
        $row = $this->control->table('notification_outbox')->where('subject_id', $result['id'])->first();

        return [$user, $org, $recipient, $result, $row];
    }

    private function state(string $id): object
    {
        return $this->control->table('notification_outbox')->where('id', $id)->first();
    }

    public function test_explicit_send_encrypted_intent_duplicate_queue_jobs_and_message_contract(): void
    {
        Mail::fake();
        Queue::fake();
        [, , , , $none] = $this->invitation(false);
        $this->assertNull($none);
        [$user, $org, $recipient, $result, $row] = $this->invitation();
        $this->assertSame('pending', $row->state);
        $this->assertSame($result['url'], decrypt($row->encrypted_payload));
        $this->assertStringNotContainsString(explode('#', $result['url'])[1], $row->encrypted_payload);
        $this->postJson('/partner-invitations', ['email' => $recipient, 'send_email' => true])->assertConflict();
        $this->assertSame(1, $this->control->table('notification_outbox')->where('subject_id', $result['id'])->count());
        app(NotificationOutbox::class)->dispatch();
        app(NotificationOutbox::class)->dispatch();
        Queue::assertPushed(DeliverNotification::class, function ($job) use ($row, $recipient, $result) {
            if ($job->notificationId !== $row->id) {
                return false;
            }
            $serialized = serialize($job);
            $this->assertStringNotContainsString($recipient, $serialized);
            $this->assertStringNotContainsString(explode('#', $result['url'])[1], $serialized);

            return true;
        });
        $outbox = app(NotificationOutbox::class);
        $outbox->deliver($user->id, $org, $row->id);
        $outbox->deliver($user->id, $org, $row->id);
        Mail::assertSent(PartnerInvitation::class, function ($mail) use ($recipient, $result, $row) {
            $this->assertTrue($mail->hasTo($recipient));
            $this->assertSame($result['url'], $mail->invitationUrl);
            $this->assertStringStartsWith(rtrim(config('app.url'), '/').'/partnerships/accept#', $mail->invitationUrl);
            $this->assertSame($row->id.'@notifications.weavdash.invalid', $mail->headers()->messageId);
            $body = $mail->render();
            $this->assertStringContainsString($result['url'], $body);
            $this->assertStringContainsString('verified recipient', $body);
            $this->assertStringNotContainsString('Private brand', $body);

            return true;
        });
        Mail::assertSentCount(1);
        $this->assertSame('captured', $this->state($row->id)->state);
        $this->assertNull($this->state($row->id)->encrypted_payload);
        $this->getJson('/brand')->assertJsonPath('invitations.0.email_state', 'captured')->assertDontSee($recipient)->assertDontSee('encrypted_payload');
    }

    public function test_business_and_notification_intent_roll_back_together(): void
    {
        [$user, $org] = $this->invitation(false);
        $id = (string) Str::uuid();
        try {
            app(TenantContext::class)->run($user->id, $org, function () use ($user, $org, $id) {
                $this->assertTrue(DB::selectOne('SELECT app_invite_partner(?::uuid,?::text,?::text) AS ok', [$id, 'rollback@example.test', hash('sha256', $id)])->ok);
                app(NotificationOutbox::class)->invitation($user->id, $org, $id, url('/partnerships/accept').'#'.str_repeat('a', 64));
                throw new RuntimeException('Rollback');
            });
        } catch (RuntimeException $e) {
            $this->assertSame('Rollback', $e->getMessage());
        }
        $this->assertFalse($this->control->table('partner_invitations')->where('id', $id)->exists());
        $this->assertFalse($this->control->table('notification_outbox')->where('subject_id', $id)->exists());
        $this->assertFalse($this->control->table('audit_logs')->where('subject_id', $id)->exists());
    }

    public function test_expiry_revocation_acceptance_and_actor_permissions_cancel_before_send(): void
    {
        Mail::fake();
        foreach (['expired', 'revoked', 'accepted', 'membership', 'role', 'verification'] as $reason) {
            [$user, $org, , $result, $row] = $this->invitation();
            if ($reason === 'expired') {
                $this->control->table('partner_invitations')->where('id', $result['id'])->update(['expires_at' => now()->subSecond()]);
            } elseif (in_array($reason, ['revoked', 'accepted'])) {
                $this->control->table('partner_invitations')->where('id', $result['id'])->update(['status' => $reason]);
            } elseif ($reason === 'verification') {
                $this->control->table('users')->where('id', $user->id)->update(['email_verified_at' => null]);
            } else {
                $this->control->table('memberships')->where('org_id', $org)->update($reason === 'role' ? ['role' => 'viewer'] : ['revoked_at' => now()]);
            }
            DB::select('SELECT app_reconcile_notifications()');
            app(NotificationOutbox::class)->deliver($user->id, $org, $row->id);
            $this->assertSame('cancelled', $this->state($row->id)->state, $reason);
            $this->assertNull($this->state($row->id)->encrypted_payload);
        }
        Mail::assertNothingSent();
    }

    public function test_ambiguous_transport_and_crashed_send_are_never_automatically_retried(): void
    {
        [$user, $org, , , $row] = $this->invitation();
        Mail::shouldReceive('mailer->to->send')->once()->andThrow(new RuntimeException('Sensitive transport error'));
        app(NotificationOutbox::class)->deliver($user->id, $org, $row->id);
        $this->assertSame('uncertain', $this->state($row->id)->state);
        $this->assertNull($this->state($row->id)->encrypted_payload);
        app(NotificationOutbox::class)->deliver($user->id, $org, $row->id);
        [$user, $org, , , $crash] = $this->invitation();
        $this->control->table('notification_outbox')->where('id', $crash->id)->update(['state' => 'sending', 'started_at' => now()->subMinutes(6)]);
        DB::select('SELECT app_reconcile_notifications()');
        app(NotificationOutbox::class)->deliver($user->id, $org, $crash->id);
        $this->assertSame('uncertain', $this->state($crash->id)->state);
        $this->assertNull($this->state($crash->id)->encrypted_payload);
    }

    public function test_log_transport_cannot_expose_capabilities_or_claim_delivery(): void
    {
        Mail::fake();
        [$user, $org, , , $row] = $this->invitation();
        config(['mail.default' => 'log']);
        app(NotificationOutbox::class)->deliver($user->id, $org, $row->id);
        $this->assertSame('pending', $this->state($row->id)->state);
        Mail::assertNothingSent();
    }

    public function test_real_redis_job_round_trip_and_recovery_after_queue_loss(): void
    {
        [$user, $org, $recipient, $result, $row] = $this->invitation();
        $queueName = 'notification-test-'.$row->id;
        $queue = Queue::connection('redis');
        $job = (new DeliverNotification($user->id, $org, $row->id))->onQueue($queueName);
        try {
            $queue->push($job, '', $queueName);
            $this->assertSame(1, $queue->size($queueName));
            $queue->clear($queueName);
            $this->assertSame('pending', $this->state($row->id)->state);
            // The PostgreSQL dispatcher can rediscover the record after Redis lost it.
            $pending = collect(DB::select('SELECT * FROM app_pending_notifications()'))->firstWhere('id', $row->id);
            $this->assertNotNull($pending);
            $queue->push((new DeliverNotification($pending->actor_id, $pending->org_id, $pending->id))->onQueue($queueName), '', $queueName);
            $this->assertSame(0, Artisan::call('queue:work', ['connection' => 'redis', '--once' => true, '--queue' => $queueName]));
            $this->assertSame('captured', $this->state($row->id)->state);
            $messages = Mail::mailer('array')->getSymfonyTransport()->messages();
            $this->assertCount(1, $messages);
            $email = $messages->first()->getOriginalMessage();
            $this->assertSame($recipient, $email->getTo()[0]->getAddress());
            $this->assertStringContainsString($result['url'], $email->getTextBody());
        } finally {
            $queue->clear($queueName);
        }
    }

    public function test_foreign_tenant_and_lower_roles_cannot_access_intent(): void
    {
        [$user, $org, , , $row] = $this->invitation();
        [$other, $foreign] = $this->invitation(false);
        app(TenantContext::class)->run($other->id, $foreign, function () use ($row, $org) {
            $this->assertSame(0, DB::table('notification_outbox')->where('id', $row->id)->count());
            $this->assertSame(0, DB::table('notification_outbox')->where('id', $row->id)->update(['state' => 'sent']));
            try {
                DB::transaction(fn () => DB::table('notification_outbox')->insert([
                    'id' => (string) Str::uuid(), 'org_id' => $org, 'actor_id' => $row->actor_id,
                    'kind' => 'partner.invitation', 'subject_id' => $row->subject_id, 'encrypted_payload' => 'forged',
                ]));
                $this->fail('Cross-tenant intent accepted');
            } catch (QueryException $e) {
                $this->assertSame('42501', $e->errorInfo[0]);
            }
        });
        foreach (['viewer', 'member', 'manager'] as $role) {
            $this->control->table('memberships')->where('org_id', $org)->update(['role' => $role]);
            app(TenantContext::class)->run($user->id, $org, function () {
                $this->assertSame(0, DB::table('notification_outbox')->count());
            });
            $this->actingAs($user)->withSession(['active_org_id' => $org])->postJson('/partner-invitations', ['email' => 'denied@example.test', 'send_email' => true])->assertConflict();
        }
        $this->assertNull(DB::selectOne('SELECT app_org() AS org')->org);
    }

    public function test_real_smtp_transport_reaches_loopback_capture_through_redis_worker(): void
    {
        [$user, $org, $recipient, $result, $row] = $this->invitation();
        $sink = new Process([PHP_BINARY, 'tests/Fixtures/smtp-capture.php'], base_path());
        $sink->setTimeout(15);
        $sink->start();
        $queueName = 'smtp-notification-test-'.$row->id;
        try {
            $this->eventually(fn () => str_contains($sink->getOutput(), "\n"), 'Local SMTP sink failed to start.');
            $port = json_decode(explode("\n", $sink->getOutput())[0], true, flags: JSON_THROW_ON_ERROR)['port'];
            config(['mail.default' => 'smtp', 'mail.from.address' => 'sender@example.test', 'mail.mailers.smtp' => [
                'transport' => 'smtp', 'host' => '127.0.0.1', 'port' => $port, 'scheme' => 'smtp', 'timeout' => 3,
            ]]);
            Queue::connection('redis')->push((new DeliverNotification($user->id, $org, $row->id))->onQueue($queueName), '', $queueName);
            $this->assertSame(0, Artisan::call('queue:work', ['connection' => 'redis', '--once' => true, '--queue' => $queueName]));
            $this->assertSame('sent', $this->state($row->id)->state);
            $this->assertNull($this->state($row->id)->encrypted_payload);
            $this->assertSame(0, $sink->wait(), $sink->getErrorOutput());
            $lines = explode("\n", trim($sink->getOutput()));
            $capture = json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('<'.$recipient.'>', $capture['recipient']);
            $body = quoted_printable_decode(base64_decode($capture['data']));
            $this->assertStringContainsString($result['url'], $body);
            $this->assertStringContainsStringIgnoringCase('Message-ID: <'.$row->id.'@notifications.weavdash.invalid>', $body);
        } finally {
            $sink->stop(0);
            Queue::connection('redis')->clear($queueName);
        }
    }

    public function test_competing_worker_waits_for_reservation_and_does_not_repeat_send(): void
    {
        [$user, $org, , , $row] = $this->invitation();
        $this->control->beginTransaction();
        $this->control->table('notification_outbox')->where('id', $row->id)->lockForUpdate()->first();
        $process = new Process([PHP_BINARY, 'tests/Fixtures/notification-probe.php', (string) $user->id, $org, $row->id], base_path(), ['APP_ENV' => 'testing', 'MAIL_MAILER' => 'array', 'TEST_MIGRATION_DB_PASSWORD' => false]);
        $process->setTimeout(15);
        $process->start();
        try {
            $this->eventually(fn () => str_contains($process->getOutput(), "\n"), 'Notification probe failed: '.$process->getErrorOutput());
            $this->assertBlocked($process);
            $this->control->table('notification_outbox')->where('id', $row->id)->update(['state' => 'sending', 'started_at' => now()]);
            $this->control->commit();
            $this->assertTrue($this->probeResult($process)['done']);
            $this->assertSame('sending', $this->state($row->id)->state);
        } finally {
            $process->stop(0);
        }
    }

    public function test_sigkill_during_smtp_and_real_redis_reclaim_do_not_repeat_external_attempt(): void
    {
        [$user, $org, , , $row] = $this->invitation();
        $sink = new Process([PHP_BINARY, 'tests/Fixtures/smtp-capture.php', 'hold'], base_path());
        $sink->start();
        $first = $replacement = null;
        $queue = 'crash-notification-'.$row->id;
        try {
            $this->eventually(fn () => str_contains($sink->getOutput(), "\n"), 'SMTP crash sink failed to start.');
            $port = json_decode(explode("\n", $sink->getOutput())[0], true, flags: JSON_THROW_ON_ERROR)['port'];
            Queue::connection('redis')->push(new DeliverNotification($user->id, $org, $row->id), '', $queue);
            $environment = ['APP_ENV' => 'testing', 'MAIL_MAILER' => 'smtp', 'MAIL_HOST' => '127.0.0.1', 'MAIL_PORT' => (string) $port,
                'MAIL_SCHEME' => 'smtp', 'MAIL_URL' => 'null', 'MAIL_USERNAME' => 'null', 'MAIL_PASSWORD' => 'null', 'MAIL_FROM_ADDRESS' => 'sender@example.test',
                'REDIS_QUEUE_RETRY_AFTER' => '4', 'TEST_MIGRATION_DB_PASSWORD' => false];
            $first = new Process([PHP_BINARY, 'artisan', 'queue:work', 'redis', '--queue='.$queue, '--once', '--tries=3'], base_path(), $environment);
            $first->start();
            try {
                $this->eventually(fn () => substr_count($sink->getOutput(), "\n") >= 2, 'Worker did not reach SMTP DATA.');
            } catch (\Throwable) {
                $this->fail('Worker did not reach SMTP DATA; state='.$this->state($row->id)->state.'; worker='.$first->getOutput().$first->getErrorOutput());
            }
            $this->assertSame('sending', $this->state($row->id)->state);
            $first->signal(SIGKILL);
            $first->wait();
            $this->assertSame(SIGKILL, $first->getTermSignal());
            $sink->stop(0);
            $replacement = new Process([PHP_BINARY, 'artisan', 'queue:work', 'redis', '--queue='.$queue, '--max-jobs=1', '--max-time=8', '--sleep=1', '--tries=3'], base_path(), $environment);
            $replacement->setTimeout(12);
            $replacement->run();
            $this->assertTrue($replacement->isSuccessful(), $replacement->getErrorOutput());
            $this->assertSame(0, Queue::connection('redis')->size($queue));
            // A second SMTP attempt would fail against the stopped sink and change
            // the state. Reclaimed delivery instead observes the committed reservation.
            $this->assertSame('sending', $this->state($row->id)->state);
            $this->control->table('notification_outbox')->where('id', $row->id)->update(['started_at' => now()->subMinutes(6)]);
            DB::select('SELECT app_reconcile_notifications()');
            $this->assertSame('uncertain', $this->state($row->id)->state);
            $this->assertNull($this->state($row->id)->encrypted_payload);
        } finally {
            $first?->stop(0);
            $replacement?->stop(0);
            $sink->stop(0);
            Queue::connection('redis')->clear($queue);
        }
    }
}
