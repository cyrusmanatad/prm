<?php

namespace Tests\Acceptance;

use App\Jobs\DeliverNotification;
use App\Jobs\QueueScheduleNotice;
use App\Mail\ScheduledNotice;
use App\Models\User;
use App\Services\Audit;
use App\Services\BusinessSchedules;
use App\Services\NotificationOutbox;
use App\Services\ScheduledNotifications;
use App\Services\Workspaces;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Process\Process;

class ScheduledNotificationTest extends AcceptanceCase
{
    private function ready(string $kind = 'weekly_digest'): array
    {
        $user = User::factory()->create();
        $org = app(Workspaces::class)->create($user->id, 'Private schedule workspace');
        $this->actingAs($user)->withSession(['active_org_id' => $org]);
        $this->putJson('/automation/preferences', ['version' => 0, 'timezone' => 'Asia/Manila', 'reminders' => true, 'weekly_digest' => true, 'follow_up_drafts' => true])->assertOk();
        $reminder = $this->postJson('/reminders', ['title' => 'Private reminder title', 'due_at' => now()->subHour()->toIso8601String()])->assertCreated()->json('id');
        $data = $kind === 'weekly_digest' ? ['weekday' => 1, 'local_time' => '09:00'] : ['subject_id' => $reminder, 'due_at' => now()->addHour()->format('Y-m-d\TH:i:sP')];
        $schedule = $this->postJson('/automation/schedules', ['name' => 'Private schedule name', 'kind' => $kind, 'consent' => true, ...$data])->assertCreated()->json('id');
        $this->control->table('business_schedules')->where('id', $schedule)->update(['next_due_at' => now()->subMinute()]);
        app(BusinessSchedules::class)->materialize($user->id, $org, $schedule);
        $run = $this->control->table('schedule_runs')->where('schedule_id', $schedule)->value('id');

        return [$user, $org, $schedule, $run, $reminder];
    }

    private function consume(array $fixture): object
    {
        [$user, $org, $schedule, $run] = $fixture;
        app(ScheduledNotifications::class)->consume($user->id, $org, $schedule, $run);

        return $this->control->table('notification_outbox')->where('schedule_run_id', $run)->first();
    }

    private function state(string $id): string
    {
        return $this->control->table('notification_outbox')->where('id', $id)->value('state');
    }

    public function test_reminder_and_weekly_digest_recipient_content_history_and_replay(): void
    {
        Mail::fake();
        foreach (['reminder', 'weekly_digest'] as $kind) {
            $f = $this->ready($kind);
            [$user, $org, $schedule, $run] = $f;
            $n = $this->consume($f);
            $this->consume($f);
            $this->assertSame(1, $this->control->table('notification_outbox')->where('schedule_run_id', $run)->count());
            $this->assertSame('completed', $this->control->table('schedule_runs')->where('id', $run)->value('state'));
            $this->assertNull($n->encrypted_payload);
            // Recipient comes from the currently verified account, never a queued snapshot/arbitrary address.
            $recipient = 'current-'.Str::uuid().'@example.test';
            $this->control->table('users')->where('id', $user->id)->update(['email' => $recipient]);
            app(NotificationOutbox::class)->deliver($user->id, $org, $n->id);
            app(NotificationOutbox::class)->deliver($user->id, $org, $n->id);
            Mail::assertSent(ScheduledNotice::class, function ($m) use ($kind, $recipient, $org, $n) {
                if ($m->kind !== 'schedule.'.$kind) {
                    return false;
                }
                $this->assertTrue($m->hasTo($recipient));
                $this->assertSame($n->id.'@notifications.weavdash.invalid', $m->headers()->messageId);
                $body = $m->render();
                foreach (['Private reminder title', 'Private schedule name', 'Private schedule workspace'] as $private) {
                    $this->assertStringNotContainsString($private, $body);
                }
                $this->assertStringContainsString(rtrim(config('app.url'), '/').'/automation', $body);
                $this->assertStringContainsString($org, $body);
                $this->assertStringContainsString('Asia/Manila', $body);
                if ($kind === 'weekly_digest') {
                    $this->assertSame(['dueReminders' => 1, 'onboardingReviews' => 0], $m->summary);
                    $this->assertStringContainsString('Due or overdue reminders: 1', $body);
                    $this->assertStringContainsString('/partner/onboarding', $body);
                }

                return true;
            });
            $this->assertSame('captured', $this->state($n->id));
            $this->getJson('/automation')->assertOk()->assertJsonPath('notices.0.state', 'captured')->assertDontSee('encrypted_payload')->assertDontSee($recipient);
        }
        Mail::assertSentCount(2);
    }

    public function test_intent_and_run_completion_rollback_together_on_failure(): void
    {
        $f = $this->ready();
        [, , , $run] = $f;
        $this->mock(Audit::class)->shouldReceive('record')->once()->andThrow(new RuntimeException('Synthetic append failure'));
        try {
            $this->consume($f);
            $this->fail('Expected rollback');
        } catch (RuntimeException $e) {
            $this->assertSame('Synthetic append failure', $e->getMessage());
        }
        $this->assertSame('ready', $this->control->table('schedule_runs')->where('id', $run)->value('state'));
        $this->assertFalse($this->control->table('notification_outbox')->where('schedule_run_id', $run)->exists());
    }

    public function test_all_writer_roles_can_receive_and_viewers_cannot_reserve(): void
    {
        Mail::fake();
        foreach (['owner', 'admin', 'manager', 'member', 'viewer'] as $role) {
            $f = $this->ready();
            [$user, $org] = $f;
            $n = $this->consume($f);
            $this->control->table('memberships')->where('org_id', $org)->update(['role' => $role]);
            app(NotificationOutbox::class)->deliver($user->id, $org, $n->id);
            if ($role === 'viewer') {
                DB::select('SELECT app_reconcile_notifications()');
            }
            $this->assertSame($role === 'viewer' ? 'cancelled' : 'captured', $this->state($n->id), $role);
        }
        Mail::assertSentCount(4);
    }

    public function test_current_basis_is_rechecked_before_consumption_and_before_reservation(): void
    {
        Mail::fake();
        foreach (['consume', 'reserve', 'reconcile'] as $phase) {
            foreach (['withdraw', 'preferences', 'version', 'subject', 'completed', 'deleted', 'revoked', 'unverified', 'entitlement'] as $change) {
                $f = $this->ready('reminder');
                [$user, $org, $schedule, $run, $reminder] = $f;
                $n = $phase === 'consume' ? null : $this->consume($f);
                if ($change === 'withdraw') {
                    $this->deleteJson('/automation/schedules/'.$schedule, ['version' => 1])->assertOk();
                } elseif ($change === 'preferences') {
                    $this->putJson('/automation/preferences', ['version' => 2, 'timezone' => 'UTC', 'reminders' => false, 'weekly_digest' => true, 'follow_up_drafts' => true])->assertOk();
                } elseif ($change === 'version') {
                    $this->control->table('business_schedules')->where('id', $schedule)->increment('version');
                } elseif (in_array($change, ['subject', 'completed'])) {
                    $this->control->table('reminders')->where('id', $reminder)->update($change === 'subject' ? ['version' => 2] : ['completed_at' => now()]);
                } elseif ($change === 'deleted') {
                    $this->control->table('reminders')->where('id', $reminder)->delete();
                } elseif ($change === 'revoked') {
                    $this->control->table('memberships')->where('org_id', $org)->update(['revoked_at' => now()]);
                } elseif ($change === 'unverified') {
                    $this->control->table('users')->where('id', $user->id)->update(['email_verified_at' => null]);
                } else {
                    $plan = (string) Str::uuid();
                    $this->control->table('pilot_plan_versions')->insert(['id' => $plan, 'name' => $plan, 'version' => 1, 'quotas' => '{}']);
                    $this->control->table('pilot_assignments')->insert(['id' => (string) Str::uuid(), 'org_id' => $org, 'plan_id' => $plan, 'version' => 1, 'starts_at' => now()->subDays(2), 'ends_at' => now()->subDay()]);
                }
                if ($phase === 'consume') {
                    app(ScheduledNotifications::class)->consume($user->id, $org, $schedule, $run);
                    $this->assertFalse($this->control->table('notification_outbox')->where('schedule_run_id', $run)->exists(), $change);
                } else {
                    if ($phase === 'reconcile') {
                        DB::select('SELECT app_reconcile_notifications()');
                    }
                    app(NotificationOutbox::class)->deliver($user->id, $org, $n->id);
                    DB::select('SELECT app_reconcile_notifications()');
                    $this->assertSame('cancelled', $this->state($n->id), $phase.':'.$change);
                }
            }
        }
        Mail::assertNothingSent();
    }

    public function test_real_rls_and_composite_keys_isolate_notices_from_peers_tenants_and_capabilities(): void
    {
        $f = $this->ready();
        [$user, $org, , $run] = $f;
        $n = $this->consume($f);
        $peer = User::factory()->create();
        $this->control->table('memberships')->insert(['org_id' => $org, 'user_id' => $peer->id, 'role' => 'admin']);
        $foreign = app(Workspaces::class)->create($user->id, 'Foreign');
        foreach ([[$peer->id, $org], [$user->id, $foreign], [0, $org]] as [$actor, $workspace]) {
            app(TenantContext::class)->run($actor, $workspace, function () use ($n): void {
                $this->assertNull(DB::table('notification_outbox')->where('id', $n->id)->first());
                $this->assertSame(0, DB::table('notification_outbox')->where('id', $n->id)->update(['state' => 'sent']));
                $this->assertFalse(DB::selectOne('SELECT app_schedule_notice_current(?::uuid) AS ok', [$n->id])->ok);
            }, requireMembership: false);
        }
        $copy = (array) $this->control->table('schedule_runs')->where('id', $run)->first();
        $copy['id'] = (string) Str::uuid();
        $copy['due_at'] = now()->subDays(2);
        $this->control->table('schedule_runs')->insert($copy);
        $run = $copy['id'];
        app(TenantContext::class)->run($peer->id, $org, function () use ($org, $peer, $run): void {
            try {
                DB::transaction(fn () => DB::table('notification_outbox')->insert(['id' => (string) Str::uuid(), 'org_id' => $org, 'actor_id' => $peer->id, 'kind' => 'schedule.reminder', 'schedule_run_id' => $run]));
                $this->fail('Foreign actor run accepted');
            } catch (QueryException $e) {
                // Composite ownership constraint fails even when actor has writer privileges.
                $this->assertSame('23503', $e->errorInfo[0]);
            }
        });
        $this->assertFalse($this->control->selectOne("SELECT has_function_privilege('weavdash_app','app_schedule_notice_basis(notification_outbox)','EXECUTE') AS ok")->ok);
    }

    public function test_redis_loss_and_replay_recover_both_ready_runs_and_email_intent(): void
    {
        $f = $this->ready('reminder');
        [$user, $org, $schedule, $run] = $f;
        $queue = Queue::connection('redis');
        $name = 'scheduled-notice-'.$run;
        try {
            $job = new QueueScheduleNotice($user->id, $org, $schedule, $run);
            $this->assertStringNotContainsString($user->email, serialize($job));
            $queue->push($job, '', $name);
            $queue->clear($name);
            $this->assertNotNull(collect(DB::select('SELECT * FROM app_ready_schedule_notices()'))->firstWhere('id', $run));
            for ($i = 0; $i < 2; $i++) {
                $queue->push($job, '', $name);
                $this->assertSame(0, Artisan::call('queue:work', ['connection' => 'redis', '--once' => true, '--queue' => $name]));
            }
            $n = $this->control->table('notification_outbox')->where('schedule_run_id', $run)->first();
            $queue->push(new DeliverNotification($user->id, $org, $n->id), '', $name);
            $queue->clear($name);
            $this->assertNotNull(collect(DB::select('SELECT * FROM app_pending_notifications()'))->firstWhere('id', $n->id));
            for ($i = 0; $i < 2; $i++) {
                $queue->push(new DeliverNotification($user->id, $org, $n->id), '', $name);
                $this->assertSame(0, Artisan::call('queue:work', ['connection' => 'redis', '--once' => true, '--queue' => $name]));
            }
            $this->assertSame('captured', $this->state($n->id));
            $this->assertCount(1, Mail::mailer('array')->getSymfonyTransport()->messages());
        } finally {
            $queue->clear($name);
        }
    }

    public function test_uncertain_scheduled_delivery_is_never_automatically_resent_and_log_transport_stays_pending(): void
    {
        $f = $this->ready();
        [$user, $org] = $f;
        $n = $this->consume($f);
        config(['mail.default' => 'log']);
        app(NotificationOutbox::class)->deliver($user->id, $org, $n->id);
        $this->assertSame('pending', $this->state($n->id));
        config(['mail.default' => 'smtp']);
        Mail::shouldReceive('mailer->to->send')->once()->andThrow(new RuntimeException('Synthetic ambiguous SMTP outcome'));
        app(NotificationOutbox::class)->deliver($user->id, $org, $n->id);
        app(NotificationOutbox::class)->deliver($user->id, $org, $n->id);
        $this->assertSame('uncertain', $this->state($n->id));
        $f = $this->ready();
        [$user, $org] = $f;
        $n = $this->consume($f);
        $this->control->table('notification_outbox')->where('id', $n->id)->update(['state' => 'sending', 'started_at' => now()->subMinutes(6)]);
        DB::select('SELECT app_reconcile_notifications()');
        app(NotificationOutbox::class)->deliver($user->id, $org, $n->id);
        $this->assertSame('uncertain', $this->state($n->id));
    }

    public function test_ready_routing_excludes_follow_up_drafts_and_contains_only_scalar_ids(): void
    {
        $f = $this->ready();
        [$user, $org, $schedule, $run] = $f;
        Queue::fake();
        app(ScheduledNotifications::class)->dispatch();
        Queue::assertPushed(QueueScheduleNotice::class, fn ($job) => $job->runId === $run && $job->actorId === $user->id && $job->orgId === $org && $job->scheduleId === $schedule);
        $lead = $this->postJson('/pipeline', ['brand_name' => 'Draft', 'currency' => 'PHP', 'value_minor' => '0'])->assertCreated()->json('id');
        $draft = $this->postJson('/automation/schedules', ['name' => 'Draft later', 'kind' => 'follow_up_draft', 'subject_id' => $lead,
            'due_at' => now()->addHour()->format('Y-m-d\TH:i:sP'), 'consent' => true])->assertCreated()->json('id');
        $this->control->table('business_schedules')->where('id', $draft)->update(['next_due_at' => now()->subMinute()]);
        app(BusinessSchedules::class)->materialize($user->id, $org, $draft);
        $r = $this->control->table('schedule_runs')->where('schedule_id', $draft)->first();
        $this->assertNull(collect(DB::select('SELECT * FROM app_ready_schedule_notices()'))->firstWhere('id', $r->id));
        try {
            app(ScheduledNotifications::class)->consume($user->id, $org, $draft, $r->id);
            $this->fail('Follow-up run consumed');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $this->assertSame('ready', $this->control->table('schedule_runs')->where('id', $r->id)->value('state'));
    }

    public function test_scheduled_digest_reaches_loopback_smtp_through_a_real_redis_worker(): void
    {
        $f = $this->ready();
        [$user, $org] = $f;
        $n = $this->consume($f);
        $sink = new Process([PHP_BINARY, 'tests/Fixtures/smtp-capture.php'], base_path());
        $sink->start();
        $name = 'scheduled-smtp-'.$n->id;
        try {
            $this->eventually(fn () => str_contains($sink->getOutput(), "\n"), 'SMTP sink connected');
            $port = json_decode(explode("\n", $sink->getOutput())[0], true, flags: JSON_THROW_ON_ERROR)['port'];
            config(['mail.default' => 'smtp', 'mail.from.address' => 'sender@example.test', 'mail.mailers.smtp' => [
                'transport' => 'smtp', 'host' => '127.0.0.1', 'port' => $port, 'scheme' => 'smtp', 'timeout' => 3,
            ]]);
            Queue::connection('redis')->push(new DeliverNotification($user->id, $org, $n->id), '', $name);
            $this->assertSame(0, Artisan::call('queue:work', ['connection' => 'redis', '--once' => true, '--queue' => $name]));
            $this->assertSame('sent', $this->state($n->id));
            $this->assertSame(0, $sink->wait());
            $lines = explode("\n", trim($sink->getOutput()));
            $capture = json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('<'.$user->email.'>', $capture['recipient']);
            $body = quoted_printable_decode(base64_decode($capture['data']));
            $this->assertStringContainsString('Due or overdue reminders: 1', $body);
            $this->assertStringContainsString(rtrim(config('app.url'), '/').'/automation', $body);
        } finally {
            $sink->stop(0);
            Queue::connection('redis')->clear($name);
        }
    }

    public function test_newer_weekly_review_suppresses_old_ready_and_pending_notices_after_outage(): void
    {
        Mail::fake();
        foreach (['ready', 'pending'] as $phase) {
            $f = $this->ready();
            [$user, $org, $schedule, $run] = $f;
            $old = $phase === 'pending' ? $this->consume($f) : null;
            $this->control->table('schedule_runs')->where('id', $run)->update(['due_at' => now()->subWeek()]);
            $this->control->table('business_schedules')->where('id', $schedule)->update(['next_due_at' => now()->subMinute()]);
            app(BusinessSchedules::class)->materialize($user->id, $org, $schedule);
            $latest = $this->control->table('schedule_runs')->where('schedule_id', $schedule)->where('id', '<>', $run)->value('id');
            if ($old) {
                app(NotificationOutbox::class)->deliver($user->id, $org, $old->id);
                $this->assertSame('cancelled', $this->state($old->id));
            } else {
                app(ScheduledNotifications::class)->consume($user->id, $org, $schedule, $run);
                $this->assertSame('cancelled', $this->control->table('schedule_runs')->where('id', $run)->value('state'));
                $this->assertFalse($this->control->table('notification_outbox')->where('schedule_run_id', $run)->exists());
            }
            $n = $this->consume([$user, $org, $schedule, $latest]);
            app(NotificationOutbox::class)->deliver($user->id, $org, $n->id);
            $this->assertSame('captured', $this->state($n->id));
        }
        Mail::assertSentCount(2);
    }

    public function test_reservation_that_locks_first_finishes_before_waiting_withdrawal(): void
    {
        $f = $this->ready();
        [$user, $org, $schedule, $run] = $f;
        $n = $this->consume($f);
        $sender = $withdraw = null;
        try {
            $this->control->beginTransaction();
            $this->control->table('notification_outbox')->where('id', $n->id)->lockForUpdate()->first();
            $environment = ['APP_ENV' => 'testing', 'MAIL_MAILER' => 'array', 'TEST_MIGRATION_DB_PASSWORD' => false];
            $sender = new Process([PHP_BINARY, 'tests/Fixtures/notification-probe.php', (string) $user->id, $org, $n->id], base_path(), $environment);
            $sender->start();
            $this->eventually(fn () => str_contains($sender->getOutput(), "\n"), 'Sender connected');
            // Sender now holds preference/schedule locks and waits on the outbox row.
            $this->assertBlocked($sender);
            $withdraw = new Process([PHP_BINARY, 'tests/Fixtures/schedule-probe.php', 'revoke', (string) $user->id, $org, $schedule, $run], base_path(), $environment);
            $withdraw->start();
            $this->eventually(fn () => str_contains($withdraw->getOutput(), "\n"), 'Withdrawal connected');
            $this->assertBlocked($withdraw);
            $this->control->commit();
            $this->assertTrue($this->probeResult($sender)['done']);
            $this->probeResult($withdraw);
            $this->assertSame('captured', $this->state($n->id));
            $this->assertFalse($this->control->table('business_schedules')->where('id', $schedule)->value('enabled'));
        } finally {
            $sender?->stop(0);
            $withdraw?->stop(0);
        }
    }

    public function test_actual_consent_withdrawal_serializes_before_send_reservation(): void
    {
        $f = $this->ready();
        [$user, $org, $schedule] = $f;
        $n = $this->consume($f);
        $probe = null;
        try {
            app(TenantContext::class)->run($user->id, $org, function () use ($user, $org, $schedule, $n, &$probe): void {
                app(BusinessSchedules::class)->revoke($user->id, $org, $schedule, 1);
                $probe = new Process([PHP_BINARY, 'tests/Fixtures/notification-probe.php', (string) $user->id, $org, $n->id], base_path(), ['APP_ENV' => 'testing', 'MAIL_MAILER' => 'array', 'TEST_MIGRATION_DB_PASSWORD' => false]);
                $probe->start();
                $this->eventually(fn () => str_contains($probe->getOutput(), "\n"), 'Send probe connected');
                $this->assertBlocked($probe);
            });
            $this->assertTrue($this->probeResult($probe)['done']);
            $this->assertSame('cancelled', $this->state($n->id));
        } finally {
            $probe?->stop(0);
        }
    }
}
