<?php

namespace Tests\Acceptance;

use App\Jobs\DeliverNotification;
use App\Mail\FollowUpNotice;
use App\Models\User;
use App\Services\FollowUpDrafts;
use App\Services\NotificationOutbox;
use App\Services\Pipeline;
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

class DeliveryOperationsTest extends AcceptanceCase
{
    private function draft(bool $review = false): array
    {
        $user = User::factory()->create();
        $org = app(Workspaces::class)->create($user->id, 'Synthetic delivery operations');
        $this->actingAs($user)->withSession(['active_org_id' => $org]);
        if ($review) {
            $this->putJson('/automation/preferences', ['version' => 0, 'timezone' => 'UTC', 'reminders' => false, 'weekly_digest' => false, 'follow_up_drafts' => false, 'follow_up_reviews' => true])->assertOk();
        }
        $lead = $this->postJson('/pipeline', ['brand_name' => 'Private client', 'currency' => 'PHP', 'value_minor' => '0', 'notes' => 'Secret CRM note'])->assertCreated()->json('id');
        $content = ['pipeline_entry_id' => $lead, 'recipient' => 'explicit@example.test', 'subject' => 'Approved subject', 'body' => "Exact body.\nSecond line."];
        $id = $this->postJson('/automation/drafts', $content)->assertCreated()->json('id');

        return [$user, $org, $lead, $id, $content];
    }

    private function queueSend(string $id): string
    {
        $this->postJson('/automation/drafts/'.$id.'/approve', ['version' => 1])->assertOk();

        return $this->postJson('/automation/drafts/'.$id.'/send', ['version' => 2])->assertOk()->json('id');
    }

    private function row(string $id): object
    {
        return $this->control->table('notification_outbox')->where('id', $id)->first();
    }

    public function test_explicit_send_uses_exact_approved_content_and_is_idempotent_without_mailbox_access(): void
    {
        Mail::fake();
        [$user, $org, , $draft] = $this->draft();
        $this->postJson('/automation/drafts/'.$draft.'/send', ['version' => 1])->assertConflict();
        $notice = $this->queueSend($draft);
        $this->postJson('/automation/drafts/'.$draft.'/send', ['version' => 2])->assertOk()->assertJsonPath('id', $notice);
        $this->recoveryFault('redis-restart');
        $this->recoveryFault('redis-loss');
        $this->assertNull($this->row($notice)->encrypted_payload);
        $this->assertSame(1, $this->control->table('notification_outbox')->where('follow_up_draft_id', $draft)->count());
        app(NotificationOutbox::class)->deliver($user->id, $org, $notice);
        app(NotificationOutbox::class)->deliver($user->id, $org, $notice);
        Mail::assertSent(FollowUpNotice::class, function ($mail) use ($notice) {
            $this->assertFalse($mail->review);
            $this->assertTrue($mail->hasTo('explicit@example.test'));
            $this->assertSame('Approved subject', $mail->envelope()->subject);
            $this->assertStringContainsString("Exact body.\nSecond line.", $mail->render());
            $this->assertStringNotContainsString('Secret CRM note', $mail->render());
            $this->assertSame($notice.'@notifications.weavdash.invalid', $mail->headers()->messageId);

            return true;
        });
        Mail::assertSentCount(1);
        $this->assertSame('captured', $this->row($notice)->state);
    }

    public function test_review_opt_in_is_account_only_generic_and_withdrawal_cancels_pending_intent(): void
    {
        Mail::fake();
        [$user, $org, , $draft] = $this->draft(true);
        $notice = $this->control->table('notification_outbox')->where('follow_up_draft_id', $draft)->where('kind', 'follow_up.review')->value('id');
        $this->recoveryFault('redis-loss');
        app(NotificationOutbox::class)->deliver($user->id, $org, $notice);
        Mail::assertSent(FollowUpNotice::class, function ($mail) use ($user) {
            $this->assertTrue($mail->review);
            $this->assertTrue($mail->hasTo($user->email));
            $this->assertStringNotContainsString('Exact body', $mail->render());
            $this->assertStringNotContainsString('explicit@example.test', $mail->render());

            return true;
        });
        $this->assertSame(1, $this->control->table('automation_consent_events')->where('org_id', $org)->where('kind', 'follow_up_review')->where('granted', true)->count());
        [$user, $org, , $draft] = $this->draft(true);
        $pending = $this->control->table('notification_outbox')->where('follow_up_draft_id', $draft)->value('id');
        $this->putJson('/automation/preferences', ['version' => 2, 'timezone' => 'UTC', 'reminders' => false, 'weekly_digest' => false, 'follow_up_drafts' => false, 'follow_up_reviews' => false])->assertOk();
        $this->assertSame('cancelled', $this->row($pending)->state);
        app(NotificationOutbox::class)->dispatch();
        app(NotificationOutbox::class)->deliver($user->id, $org, $pending);
        $this->assertSame('cancelled', $this->row($pending)->state);
        Mail::assertSentCount(1);
    }

    public function test_reply_edit_revocation_and_other_owners_cannot_send_obsolete_content(): void
    {
        Mail::fake();
        foreach (['edit', 'reply', 'revoked'] as $change) {
            [$user, $org, $lead, $draft, $content] = $this->draft();
            $notice = $this->queueSend($draft);
            $peer = User::factory()->create();
            $this->control->table('memberships')->insert(['org_id' => $org, 'user_id' => $peer->id, 'role' => 'admin']);
            $this->actingAs($peer)->withSession(['active_org_id' => $org]);
            $this->postJson('/automation/drafts/'.$draft.'/send', ['version' => 2])->assertNotFound();
            $this->getJson('/automation')->assertJsonCount(0, 'notices');
            app(TenantContext::class)->run($peer->id, $org, fn () => $this->assertSame(0, DB::table('notification_outbox')->where('id', $notice)->update(['state' => 'sending'])));
            $this->actingAs($user)->withSession(['active_org_id' => $org]);
            if ($change === 'edit') {
                unset($content['pipeline_entry_id']);
                $this->patchJson('/automation/drafts/'.$draft, [...$content, 'version' => 2, 'recipient' => 'different@example.test'])->assertOk();
            } elseif ($change === 'reply') {
                $this->postJson('/pipeline/'.$lead.'/activities', ['version' => 1, 'kind' => 'reply', 'body' => 'Manual reply'])->assertCreated();
            } else {
                $this->control->table('memberships')->where('org_id', $org)->where('user_id', $user->id)->update(['revoked_at' => now()]);
            }
            $this->assertSame('cancelled', $this->row($notice)->state);
            $this->recoveryFault('redis-restart');
            if ($change === 'revoked') {
                $this->control->table('memberships')->where('org_id', $org)->where('user_id', $user->id)->update(['revoked_at' => null]);
            }
            app(NotificationOutbox::class)->deliver($user->id, $org, $notice);
            $this->assertSame('cancelled', $this->row($notice)->state);
        }
        Mail::assertNothingSent();
    }

    public function test_budget_deferral_and_transport_backoff_remain_durable_without_retrying_uncertain_sends(): void
    {
        Mail::fake();
        [$user, $org, $lead, $draft, $content] = $this->draft();
        $first = $this->queueSend($draft);
        $secondDraft = $this->postJson('/automation/drafts', $content)->assertCreated()->json('id');
        $second = $this->queueSend($secondDraft);
        config(['notifications.workspace_per_minute' => 1]);
        app(NotificationOutbox::class)->deliver($user->id, $org, $first);
        app(NotificationOutbox::class)->deliver($user->id, $org, $second);
        $this->assertSame('pending', $this->row($second)->state);
        $this->assertSame('workspace_budget', $this->row($second)->failure_code);
        $this->assertSame(1, $this->row($second)->defer_count);
        $this->recoveryFault('redis-restart');
        app(NotificationOutbox::class)->deliver($user->id, $org, $second);
        $this->assertSame(1, $this->row($second)->defer_count);
        $this->control->table('notification_outbox')->where('id', $second)->update(['available_at' => now()->subMinute()]);
        config(['notifications.workspace_per_minute' => 0, 'mail.default' => 'log']);
        app(NotificationOutbox::class)->deliver($user->id, $org, $second);
        $this->assertSame('transport_unavailable', $this->row($second)->failure_code);
        $this->assertSame(2, $this->row($second)->defer_count);
        $this->control->table('notification_outbox')->where('id', $second)->update(['available_at' => now()->subMinute()]);
        config(['mail.default' => 'array']);
        app(NotificationOutbox::class)->deliver($user->id, $org, $second);
        $this->assertSame('captured', $this->row($second)->state);
        Mail::assertSentCount(2);
        app(TenantContext::class)->run($user->id, $org, fn () => $this->assertSame(0, DB::table('notification_delivery_windows')->count()));
    }

    public function test_dispatch_lease_recovers_lost_queue_publication_and_round_robins_workspaces(): void
    {
        Queue::fake();
        [$user, $org, , $draft, $content] = $this->draft();
        $notice = $this->queueSend($draft);
        for ($i = 0; $i < 101; $i++) {
            $id = $this->postJson('/automation/drafts', $content)->assertCreated()->json('id');
            $this->control->table('notification_outbox')->insert(['id' => (string) Str::uuid(), 'org_id' => $org, 'actor_id' => $user->id, 'kind' => 'follow_up.review', 'follow_up_draft_id' => $id, 'follow_up_version' => 1, 'follow_up_preference_version' => 1, 'created_at' => now()->subDay()]);
        }
        // Use routing without reconciliation to exercise selection under a large historical backlog.
        [$peer, $foreign, , $other] = $this->draft();
        $otherNotice = $this->queueSend($other);
        app(TenantContext::class)->run($user->id, $org, fn () => DB::select('SELECT app_notification_budget(0,0)'));
        $this->recoveryFault('redis-restart');
        $routes = collect(DB::select('SELECT * FROM app_pending_notifications()'));
        $ours = $routes->filter(fn ($route) => in_array($route->org_id, [$org, $foreign], true))->values();
        $this->assertSame($otherNotice, $ours->first()->id);
        $this->assertNotNull($routes->firstWhere('id', $otherNotice));
        $this->assertLessThanOrEqual(100, $routes->count());
        app(NotificationOutbox::class)->dispatch();
        Queue::assertPushed(DeliverNotification::class, fn ($job) => $job->notificationId === $notice);
        $queued = Queue::pushed(DeliverNotification::class)->filter(fn ($job) => in_array($job->orgId, [$org, $foreign], true))->values();
        $this->assertSame($otherNotice, $queued->first()->notificationId);
        $this->assertNull(collect(DB::select('SELECT * FROM app_pending_notifications()'))->firstWhere('id', $notice));
        $this->control->table('notification_outbox')->where('id', $notice)->update(['dispatch_after' => now()->subSecond()]);
        app(NotificationOutbox::class)->dispatch();
        $this->assertCount(2, Queue::pushed(DeliverNotification::class, fn ($job) => $job->notificationId === $notice));
    }

    public function test_operator_metadata_and_resolution_require_scoped_grants_current_mfa_and_never_resend(): void
    {
        [$owner, $org, , $draft] = $this->draft();
        $notice = $this->queueSend($draft);
        $this->control->table('notification_outbox')->where('id', $notice)->update(['state' => 'uncertain', 'encrypted_payload' => null]);
        $this->getJson('/admin/notifications')->assertForbidden();
        $operator = User::factory()->create();
        $this->control->table('platform_grants')->insert(['user_id' => $operator->id, 'permissions' => '{notifications.read,notifications.reconcile}']);
        $this->control->table('users')->where('id', $operator->id)->update(['admin_totp_confirmed_at' => now()]);
        $this->actingAs($operator->fresh())->withSession(['platform_mfa' => ['user' => $operator->id, 'version' => 1, 'until' => time() + 900]]);
        $this->getJson('/admin/notifications?workspace='.$org.'&state=uncertain')->assertOk()->assertJsonPath('notices.0.id', $notice)->assertDontSee('explicit@example.test')->assertDontSee('Exact body')->assertDontSee('Secret CRM note')->assertJsonMissingPath('notices.0.encrypted_payload');
        $data = ['version' => 0, 'resolution' => 'not_sent', 'reason' => 'Synthetic provider evidence'];
        $this->postJson('/admin/notifications/'.$notice.'/resolve', $data)->assertOk();
        $this->postJson('/admin/notifications/'.$notice.'/resolve', $data)->assertConflict();
        $this->recoveryFault('redis-loss');
        $this->assertSame('uncertain', $this->row($notice)->state);
        $this->assertSame(1, $this->control->table('notification_resolutions')->where('notification_id', $notice)->count());
        $this->assertSame(1, $this->control->table('platform_audit')->where('target', $notice)->where('event', 'notification.resolved')->count());
        $this->withSession(['platform_mfa' => ['user' => $operator->id, 'version' => 1, 'until' => time() - 1]])->getJson('/admin/notifications')->assertForbidden();
        app(NotificationOutbox::class)->deliver($owner->id, $org, $notice);
        $this->assertSame('uncertain', $this->row($notice)->state);
    }

    public function test_send_intent_and_operator_resolution_rollback_with_audit(): void
    {
        [$user, $org, , $draft] = $this->draft();
        $this->postJson('/automation/drafts/'.$draft.'/approve', ['version' => 1])->assertOk();
        try {
            app(TenantContext::class)->run($user->id, $org, function () use ($user, $org, $draft) {
                app(FollowUpDrafts::class)->queueSend($user->id, $org, $draft, 2);
                throw new RuntimeException('Rollback send');
            });
        } catch (RuntimeException $error) {
            $this->assertSame('Rollback send', $error->getMessage());
        }
        $this->assertSame(0, $this->control->table('notification_outbox')->where('follow_up_draft_id', $draft)->count());
    }

    public function test_reply_and_follow_up_send_reservation_serialize_in_both_lock_orders(): void
    {
        foreach (['reply-first', 'send-first'] as $order) {
            Mail::fake();
            [$user, $org, $lead, $draft] = $this->draft();
            $notice = $this->queueSend($draft);
            $probe = null;
            $spawn = function (bool $send) use ($user, $org, $lead, $notice, &$probe): void {
                $args = $send
                    ? [PHP_BINARY, 'tests/Fixtures/delivery-probe.php', (string) $user->id, $org, $notice]
                    : [PHP_BINARY, 'tests/Fixtures/reply-probe.php', 'reply', (string) $user->id, $org, $lead, '', ''];
                $probe = new Process($args, base_path(), ['TEST_MIGRATION_DB_PASSWORD' => false]);
                $probe->start();
                $this->eventually(fn () => str_contains($probe->getOutput(), "\n"), 'Delivery probe connected');
                $this->assertBlocked($probe);
            };
            try {
                if ($order === 'reply-first') {
                    app(TenantContext::class)->run($user->id, $org, function () use ($user, $org, $lead, $spawn): void {
                        app(Pipeline::class)->addActivity($user->id, $org, $lead, 1, 'reply', 'Concurrent reply');
                        $spawn(true);
                    });
                } else {
                    $armed = true;
                    DB::listen(function ($query) use (&$armed, $spawn): void {
                        if ($armed && str_starts_with($query->sql, 'update "notification_outbox"') && in_array('sending', $query->bindings, true)) {
                            $armed = false;
                            $spawn(false);
                        }
                    });
                    try {
                        app(NotificationOutbox::class)->deliver($user->id, $org, $notice);
                    } finally {
                        $armed = false;
                    }
                }
                $this->assertTrue($this->probeResult($probe)['done']);
                $this->assertSame($order === 'reply-first' ? 'cancelled' : 'captured', $this->row($notice)->state);
                $this->assertSame('cancelled', $this->control->table('follow_up_drafts')->where('id', $draft)->value('state'));
                Mail::assertSentCount($order === 'reply-first' ? 0 : 1);
            } finally {
                $probe?->stop(0);
            }
        }
    }

    public function test_global_budget_serializes_competing_workspaces_before_reservation(): void
    {
        Mail::fake();
        [$user, $org, , $draft] = $this->draft();
        $first = $this->queueSend($draft);
        [$peer, $foreign, , $other] = $this->draft();
        $second = $this->queueSend($other);
        $this->eventually(fn () => (float) $this->control->selectOne('SELECT extract(second FROM clock_timestamp()) AS second')->second < 55, 'Start away from the minute boundary', 8);
        $this->control->table('notification_delivery_windows')->where('scope', 'global')->update(['window_start' => now()->subDay()]);
        config(['notifications.global_per_minute' => 1]);
        $armed = true;
        $probe = null;
        DB::listen(function ($query) use (&$armed, &$probe, $peer, $foreign, $second): void {
            if ($armed && str_contains($query->sql, 'app_notification_budget')) {
                $armed = false;
                $probe = new Process([PHP_BINARY, 'tests/Fixtures/delivery-probe.php', (string) $peer->id, $foreign, $second], base_path(), ['NOTIFICATION_GLOBAL_PER_MINUTE' => '1', 'TEST_MIGRATION_DB_PASSWORD' => false]);
                $probe->start();
                $this->eventually(fn () => str_contains($probe->getOutput(), "\n"), 'Budget probe connected');
                $this->assertBlocked($probe);
            }
        });
        try {
            app(NotificationOutbox::class)->deliver($user->id, $org, $first);
            $this->assertTrue($this->probeResult($probe)['done']);
            $this->assertSame('captured', $this->row($first)->state);
            $this->assertSame('pending', $this->row($second)->state);
            $this->assertSame('global_budget', $this->row($second)->failure_code);
            Mail::assertSentCount(1);
        } finally {
            $armed = false;
            $probe?->stop(0);
        }
    }

    public function test_queue_publication_failure_is_sanitized_and_recovers_after_durable_backoff(): void
    {
        [$user, $org, , $draft] = $this->draft();
        $notice = $this->queueSend($draft);
        Queue::shouldReceive('connection')->andThrow(new RuntimeException('Sensitive queue exception'));
        app(NotificationOutbox::class)->dispatch();
        $this->assertSame('pending', $this->row($notice)->state);
        $this->assertSame('queue_unavailable', $this->row($notice)->failure_code);
        $this->assertSame(1, $this->row($notice)->defer_count);
        $this->assertNull($this->row($notice)->started_at);
        $this->control->table('notification_outbox')->where('id', $notice)->update(['available_at' => now()->subSecond(), 'dispatch_after' => now()->subSecond()]);
        Queue::fake();
        app(NotificationOutbox::class)->dispatch();
        Queue::assertPushed(DeliverNotification::class, fn ($job) => $job->notificationId === $notice);
    }

    public function test_scoped_offline_grants_read_only_access_stale_proofs_and_resolution_rollback(): void
    {
        [, , , $draft] = $this->draft();
        $notice = $this->queueSend($draft);
        $this->control->table('notification_outbox')->where('id', $notice)->update(['state' => 'uncertain']);
        $operator = User::factory()->create();
        $arguments = ['user' => (string) $operator->id, '--permission' => ['notifications.read'], '--operator' => 'Synthetic operator', '--reason' => 'Scoped diagnostics'];
        $this->assertSame(1, Artisan::call('platform:grant', $arguments));
        $default = DB::getDefaultConnection();
        DB::setDefaultConnection('acceptance_control');
        try {
            $this->assertSame(0, Artisan::call('platform:grant', $arguments));
        } finally {
            DB::setDefaultConnection($default);
        }
        $this->control->table('users')->where('id', $operator->id)->update(['admin_totp_confirmed_at' => now()]);
        $this->actingAs($operator->fresh())->withSession(['platform_mfa' => ['user' => $operator->id, 'version' => 1, 'until' => time() + 900]]);
        $this->getJson('/admin/notifications')->assertOk()->assertJsonPath('canResolve', false);
        $this->postJson('/admin/notifications/'.$notice.'/resolve', ['version' => 0, 'resolution' => 'not_sent', 'reason' => 'Read-only denial'])->assertForbidden();
        $this->control->table('platform_grants')->where('user_id', $operator->id)->update(['permissions' => '{notifications.read,notifications.reconcile}', 'version' => 2]);
        $this->getJson('/admin/notifications')->assertForbidden();
        try {
            app(TenantContext::class)->run($operator->id, null, function () use ($notice): void {
                DB::select("SELECT set_config('app.admin_version','2',true)");
                DB::select('SELECT app_platform_resolve_notification(?::uuid,0,?,?)', [$notice, 'not_sent', 'Rollback evidence']);
                throw new RuntimeException('Rollback resolution');
            });
        } catch (RuntimeException $error) {
            $this->assertSame('Rollback resolution', $error->getMessage());
        }
        $this->assertSame(0, $this->control->table('notification_resolutions')->where('notification_id', $notice)->count());
        $this->assertSame(0, $this->control->table('platform_audit')->where('target', $notice)->where('event', 'notification.resolved')->count());
        $this->control->table('platform_grants')->where('user_id', $operator->id)->update(['revoked_at' => now()]);
        $this->withSession(['platform_mfa' => ['user' => $operator->id, 'version' => 2, 'until' => time() + 900]])->getJson('/admin/notifications')->assertForbidden();
    }

    public function test_follow_up_reaches_loopback_smtp_through_real_redis_with_no_private_crm_interpolation(): void
    {
        [$user, $org, , $draft] = $this->draft();
        $notice = $this->queueSend($draft);
        $sink = new Process([PHP_BINARY, 'tests/Fixtures/smtp-capture.php'], base_path());
        $sink->setTimeout(15);
        $sink->start();
        $queue = 'follow-up-smtp-'.$notice;
        try {
            $this->eventually(fn () => str_contains($sink->getOutput(), "\n"), 'Loopback SMTP connected');
            $port = json_decode(explode("\n", $sink->getOutput())[0], true, flags: JSON_THROW_ON_ERROR)['port'];
            config(['mail.default' => 'smtp', 'mail.from.address' => 'sender@example.test', 'mail.mailers.smtp' => ['transport' => 'smtp', 'host' => '127.0.0.1', 'port' => $port, 'scheme' => 'smtp', 'timeout' => 3]]);
            $job = new DeliverNotification($user->id, $org, $notice);
            $this->assertStringNotContainsString('explicit@example.test', serialize($job));
            $this->assertStringNotContainsString('Exact body', serialize($job));
            Queue::connection('redis')->push($job, '', $queue);
            $this->assertSame(0, Artisan::call('queue:work', ['connection' => 'redis', '--once' => true, '--queue' => $queue]));
            $this->assertSame(0, $sink->wait(), $sink->getErrorOutput());
            $lines = explode("\n", trim($sink->getOutput()));
            $capture = json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR);
            $body = quoted_printable_decode(base64_decode($capture['data']));
            $this->assertSame('<explicit@example.test>', $capture['recipient']);
            $this->assertStringContainsString('Subject: Approved subject', $body);
            $this->assertStringContainsString('Reply-To:', $body);
            $this->assertStringContainsString($user->email, $body);
            $this->assertStringContainsString('Exact body.', $body);
            $this->assertStringContainsString('Content-Type: text/plain', $body);
            $this->assertStringNotContainsString('Secret CRM note', $body);
            app(NotificationOutbox::class)->deliver($user->id, $org, $notice);
            $this->assertSame('sent', $this->row($notice)->state);
        } finally {
            $sink->stop(0);
            Queue::connection('redis')->clear($queue);
        }
    }

    public function test_forced_rls_denies_direct_operational_writes_and_rollback_preserves_new_history(): void
    {
        [$user, $org, , $draft] = $this->draft();
        $notice = $this->queueSend($draft);
        foreach (['notification_resolutions', 'notification_delivery_windows'] as $table) {
            try {
                app(TenantContext::class)->run($user->id, $org, function () use ($table, $notice, $user): void {
                    DB::table($table)->insert($table === 'notification_resolutions'
                        ? ['notification_id' => $notice, 'resolution' => 'not_sent', 'actor_id' => $user->id]
                        : ['scope' => 'forged', 'window_start' => now(), 'used' => 0]);
                });
                $this->fail('Runtime must not directly mutate operational tables.');
            } catch (QueryException $error) {
                $this->assertSame('42501', $error->errorInfo[0]);
            }
        }
        $this->control->table('notification_resolutions')->insert(['notification_id' => $notice, 'resolution' => 'unknown_closed', 'actor_id' => $user->id]);
        $count = $this->control->table('notification_outbox')->count();
        $default = DB::getDefaultConnection();
        DB::setDefaultConnection('acceptance_control');
        try {
            foreach (['2026_11_09_000003_add_follow_up_delivery.php', '2026_11_09_000002_add_delivery_controls.php'] as $file) {
                $migration = require database_path('migrations/'.$file);
                try {
                    $migration->down();
                    $this->fail('Rollback must preserve recorded delivery/resolution history.');
                } catch (RuntimeException $error) {
                    $this->assertStringContainsString('refuses', $error->getMessage());
                }
            }
        } finally {
            DB::setDefaultConnection($default);
        }
        $this->assertSame($count, $this->control->table('notification_outbox')->count());
        $this->assertSame(1, $this->control->table('notification_resolutions')->where('notification_id', $notice)->count());
    }

    public function test_invalid_budget_configuration_never_silently_disables_limits(): void
    {
        Mail::fake();
        foreach (['workspace_per_minute', 'global_per_minute'] as $field) {
            foreach ([-1, 'invalid'] as $value) {
                [$user, $org, , $draft] = $this->draft();
                $notice = $this->queueSend($draft);
                config(['notifications.workspace_per_minute' => 0, 'notifications.global_per_minute' => 0, 'notifications.'.$field => $value]);
                app(NotificationOutbox::class)->deliver($user->id, $org, $notice);
                $this->assertSame('pending', $this->row($notice)->state);
                $this->assertNull($this->row($notice)->started_at);
                $this->assertSame($field === 'workspace_per_minute' ? 'workspace_budget' : 'global_budget', $this->row($notice)->failure_code);
            }
        }
        Mail::assertNothingSent();
    }
}
