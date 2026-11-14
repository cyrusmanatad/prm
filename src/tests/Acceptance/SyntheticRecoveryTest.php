<?php

namespace Tests\Acceptance;

use App\Jobs\DeliverNotification;
use App\Jobs\MaterializeSchedule;
use App\Jobs\PrepareFollowUp;
use App\Jobs\QueueScheduleNotice;
use App\Models\User;
use App\Services\BusinessSchedules;
use App\Services\NotificationOutbox;
use App\Services\Workspaces;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class SyntheticRecoveryTest extends AcceptanceCase
{
    private function invitation(): object
    {
        $user = User::factory()->create();
        $org = app(Workspaces::class)->create($user->id, 'Synthetic recovery brand', 'brand');
        $id = $this->actingAs($user)->withSession(['active_org_id' => $org])->postJson('/partner-invitations', [
            'email' => 'recovery-'.Str::uuid().'@example.test', 'send_email' => true,
        ])->assertCreated()->json('id');

        return $this->control->table('notification_outbox')->where('subject_id', $id)->first();
    }

    private function row(object $row): object
    {
        return $this->control->table('notification_outbox')->where('id', $row->id)->first();
    }

    private function worker(object $row, array $environment = []): void
    {
        $queue = 'recovery-'.$row->id;
        $this->job(new DeliverNotification($row->actor_id, $row->org_id, $row->id), $queue, $environment);
    }

    private function job(object $job, string $queue, array $environment = []): void
    {
        Queue::connection('redis')->push($job, '', $queue);
        $process = new Process([PHP_BINARY, 'artisan', 'queue:work', 'redis', '--queue='.$queue, '--once', '--tries=1'], base_path(), [
            'APP_ENV' => 'testing', 'TEST_MIGRATION_DB_PASSWORD' => false,
            'MAIL_MAILER' => 'array', ...$environment,
        ]);
        try {
            $process->setTimeout(15);
            $process->run();
            $this->assertTrue($process->isSuccessful(), $process->getOutput().$process->getErrorOutput());
            $this->assertSame(0, Queue::connection('redis')->size($queue));
        } finally {
            $process->stop(0);
            Queue::connection('redis')->clear($queue);
        }
    }

    public function test_all_scheduled_stages_reconstruct_after_service_queue_loss_in_fresh_workers(): void
    {
        foreach (['reminder', 'weekly_digest', 'follow_up_draft'] as $kind) {
            $user = User::factory()->create();
            $org = app(Workspaces::class)->create($user->id, 'Recovery stages');
            $this->actingAs($user)->withSession(['active_org_id' => $org]);
            $this->putJson('/automation/preferences', ['version' => 0, 'timezone' => 'UTC', 'reminders' => true,
                'weekly_digest' => true, 'follow_up_drafts' => true, 'follow_up_reviews' => true])->assertOk();
            $subject = $kind === 'reminder'
                ? $this->postJson('/reminders', ['title' => 'Recovery reminder', 'due_at' => now()->subHour()->toIso8601String()])->assertCreated()->json('id')
                : $this->postJson('/pipeline', ['brand_name' => 'Recovery lead', 'currency' => 'PHP', 'value_minor' => '0'])->assertCreated()->json('id');
            $data = $kind === 'weekly_digest' ? ['weekday' => 1, 'local_time' => '09:00']
                : ['subject_id' => $subject, 'due_at' => now()->addHour()->toIso8601String()];
            $id = $this->postJson('/automation/schedules', ['name' => 'Recovery '.$kind, 'kind' => $kind, 'consent' => true, ...$data])->assertCreated()->json('id');
            $this->control->table('business_schedules')->where('id', $id)->update(['next_due_at' => now()->subMinute()]);
            $queue = 'recovery-stage-'.$id;
            $materialize = new MaterializeSchedule($user->id, $org, $id);
            Queue::connection('redis')->push($materialize, '', $queue);
            $this->recoveryFault('redis-loss');
            Queue::connection('redis')->clear($queue);
            $this->assertNotNull(collect(DB::select('SELECT * FROM app_due_schedules()'))->firstWhere('id', $id));
            $this->job($materialize, $queue);
            $run = $this->control->table('schedule_runs')->where('schedule_id', $id)->first();
            $this->assertNotNull($run);
            $consumer = $kind === 'follow_up_draft'
                ? new PrepareFollowUp($user->id, $org, $id, $run->id)
                : new QueueScheduleNotice($user->id, $org, $id, $run->id);
            Queue::connection('redis')->push($consumer, '', $queue);
            $this->recoveryFault('redis-restart');
            $this->assertSame(1, Queue::connection('redis')->size($queue));
            $this->recoveryFault('redis-loss');
            Queue::connection('redis')->clear($queue);
            $function = $kind === 'follow_up_draft' ? 'app_ready_follow_up_drafts' : 'app_ready_schedule_notices';
            $this->assertNotNull(collect(DB::select('SELECT * FROM '.$function.'()'))->firstWhere('id', $run->id));
            $this->job($consumer, $queue);
            $this->job($consumer, $queue);
            $this->assertSame('completed', $this->control->table('schedule_runs')->where('id', $run->id)->value('state'));
            if ($kind === 'follow_up_draft') {
                $this->assertSame(1, $this->control->table('follow_up_drafts')->where('schedule_run_id', $run->id)->count());
                $draft = $this->control->table('follow_up_drafts')->where('schedule_run_id', $run->id)->first();
                $notice = $this->control->table('notification_outbox')->where('follow_up_draft_id', $draft->id)->first();
            } else {
                $this->assertSame(1, $this->control->table('notification_outbox')->where('schedule_run_id', $run->id)->count());
                $notice = $this->control->table('notification_outbox')->where('schedule_run_id', $run->id)->first();
            }
            $this->assertNotNull($notice);
            $this->recoveryFault('redis-loss');
            $this->worker($notice);
            $this->assertSame('captured', $this->row($notice)->state);
            $completed = $this->row($notice)->completed_at;
            $this->worker($notice);
            $this->assertSame($completed, $this->row($notice)->completed_at);
        }
    }

    public function test_service_smtp_outage_keeps_uncertain_terminal_and_new_workspace_progresses_after_restart(): void
    {
        if (! getenv('RECOVERY_CONTROL')) {
            $this->markTestSkipped('Requires the isolated recovery broker.');
        }
        $failed = $this->invitation();
        $healthy = $this->invitation();
        $this->recoveryFault('smtp-stop');
        try {
            $this->worker($failed, ['MAIL_MAILER' => 'smtp']);
            $this->assertSame('uncertain', $this->row($failed)->state);
            $this->assertNull($this->row($failed)->encrypted_payload);
        } finally {
            $this->recoveryFault('smtp-start');
        }
        $this->recoveryFault('redis-restart');
        DB::select('SELECT app_reconcile_notifications()');
        $this->worker($failed, ['MAIL_MAILER' => 'smtp']);
        $this->assertSame('uncertain', $this->row($failed)->state);
        $this->worker($healthy, ['MAIL_MAILER' => 'smtp']);
        $this->assertSame('sent', $this->row($healthy)->state);
        $completed = $this->row($healthy)->completed_at;
        $this->worker($healthy, ['MAIL_MAILER' => 'smtp']);
        $this->assertSame($completed, $this->row($healthy)->completed_at);
    }

    public function test_onboarding_replacement_and_each_lifecycle_notice_recover_through_real_workers(): void
    {
        if (! getenv('RECOVERY_CONTROL')) {
            $this->markTestSkipped('Requires the isolated SMTP service and recovery broker.');
        }
        $user = User::factory()->create();
        $org = app(Workspaces::class)->create($user->id, 'Recovery onboarding');
        $this->actingAs($user)->withSession(['active_org_id' => $org]);
        $lead = $this->postJson('/pipeline', ['brand_name' => 'Synthetic onboarding', 'currency' => 'PHP', 'value_minor' => '0'])->assertCreated()->json('id');
        $invitation = $this->postJson('/onboarding-requests', ['pipeline_entry_id' => $lead, 'label' => 'Synthetic recovery',
            'notify_submission' => true, 'send_email' => true, 'recipient_email' => 'initial@example.test'])->assertCreated()->json();
        $id = $invitation['id'];
        $notice = fn (string $kind) => $this->control->table('notification_outbox')->where('onboarding_request_id', $id)
            ->where('kind', 'onboarding.'.$kind)->orderByDesc('created_at')->first();
        $version = fn () => $this->control->table('onboarding_requests')->where('id', $id)->value('version');
        $recover = function (object $row): void {
            $queue = 'recovery-'.$row->id;
            Queue::connection('redis')->push(new DeliverNotification($row->actor_id, $row->org_id, $row->id), '', $queue);
            $this->recoveryFault('redis-loss');
            $this->assertSame(0, Queue::connection('redis')->size($queue));
            $this->worker($row, ['MAIL_MAILER' => 'smtp']);
            $this->assertSame('sent', $this->row($row)->state);
            $completed = $this->row($row)->completed_at;
            $this->worker($row, ['MAIL_MAILER' => 'smtp']);
            $this->assertSame($completed, $this->row($row)->completed_at);
        };
        $old = $notice('invitation');
        $replacement = $this->postJson('/onboarding-requests/'.$id.'/renew', ['version' => $version(),
            'notify_submission' => true, 'send_email' => true, 'recipient_email' => 'replacement@example.test'])->assertOk()->json();
        $recover($notice('invitation'));
        $this->worker($old, ['MAIL_MAILER' => 'smtp']);
        $this->assertSame('cancelled', $this->row($old)->state);
        $this->postJson('/onboarding/session', ['token' => explode('#token=', $replacement['url'])[1]])->assertOk();
        foreach (['correct', 'accept'] as $action) {
            $this->postJson('/onboarding/form/'.$id.'/submit', ['version' => $version(), 'idempotency_key' => (string) Str::uuid(),
                'consent' => true, 'business_name' => 'Synthetic business', 'contact_name' => 'Synthetic contact',
                'business_email' => 'synthetic@example.test', 'brief' => 'Synthetic recovery'])->assertOk();
            $recover($notice('submitted'));
            $this->postJson('/onboarding-requests/'.$id.'/'.$action, ['version' => $version(), 'note' => 'Synthetic review',
                'send_email' => true, 'recipient_email' => 'explicit@example.test'])->assertOk();
            $recover($notice($action === 'correct' ? 'corrected' : 'accepted'));
        }
        $this->assertSame(6, $this->control->table('notification_outbox')->where('onboarding_request_id', $id)->count());
    }

    public function test_budget_and_backoff_survive_fresh_workers_and_redis_loss(): void
    {
        $first = $this->invitation();
        $second = $this->invitation();
        // Previous cases share this disposable database. Start a fresh global window.
        $this->control->table('notification_delivery_windows')->where('scope', 'global')->update(['window_start' => now()->subMinutes(2)]);
        $this->worker($first, ['NOTIFICATION_GLOBAL_PER_MINUTE' => '1']);
        $windows = $this->control->table('notification_delivery_windows')->orderBy('scope')->get()->toJson();
        $this->recoveryFault('redis-loss');
        $this->assertSame($windows, $this->control->table('notification_delivery_windows')->orderBy('scope')->get()->toJson());
        $this->worker($second, ['NOTIFICATION_GLOBAL_PER_MINUTE' => '1']);
        $state = $this->row($second);
        // If a real minute elapsed, consume the new minute and create another intent.
        if ($state->state === 'captured') {
            $second = $this->invitation();
            $this->worker($second, ['NOTIFICATION_GLOBAL_PER_MINUTE' => '1']);
            $state = $this->row($second);
        }
        $this->assertSame('pending', $state->state);
        $this->assertSame('global_budget', $state->failure_code);
        $this->assertSame(1, $state->defer_count);
        $this->recoveryFault('redis-restart');
        $this->worker($second);
        $this->assertSame($state->available_at, $this->row($second)->available_at);
        $this->assertSame(1, $this->row($second)->defer_count);
        $this->control->table('notification_outbox')->where('id', $second->id)->update(['available_at' => now()->subSecond()]);
        $this->worker($second);
        $this->assertSame('captured', $this->row($second)->state);
    }

    public function test_actual_redis_outage_defers_publication_with_sanitized_durable_feedback(): void
    {
        if (! getenv('RECOVERY_CONTROL')) {
            $this->markTestSkipped('Requires the isolated recovery broker.');
        }
        $row = $this->invitation();
        $this->recoveryFault('redis-stop');
        try {
            for ($i = 0; $i < 20 && $this->row($row)->defer_count === 0; $i++) {
                $this->assertSame(0, app(NotificationOutbox::class)->dispatch());
            }
            $deferred = $this->row($row);
            $this->assertSame('pending', $deferred->state);
            $this->assertSame('queue_unavailable', $deferred->failure_code);
            $this->assertSame(1, $deferred->defer_count);
            $this->assertNotNull($deferred->encrypted_payload);
        } finally {
            $this->recoveryFault('redis-start');
        }
        $this->worker($row);
        $this->assertSame($deferred->available_at, $this->row($row)->available_at);
        $this->assertSame('pending', $this->row($row)->state);
        $this->control->table('notification_outbox')->where('id', $row->id)->update(['available_at' => now()->subSecond()]);
        $this->worker($row);
        $this->assertSame('captured', $this->row($row)->state);
    }

    public function test_smtp_disconnect_after_data_is_uncertain_and_replay_does_not_contact_smtp(): void
    {
        $row = $this->invitation();
        $sink = new Process([PHP_BINARY, 'tests/Fixtures/smtp-capture.php', 'disconnect'], base_path());
        $sink->start();
        try {
            $this->eventually(fn () => str_contains($sink->getOutput(), "\n"), 'SMTP sink did not start.');
            $port = json_decode(explode("\n", $sink->getOutput())[0], true, flags: JSON_THROW_ON_ERROR)['port'];
            $environment = ['MAIL_MAILER' => 'smtp', 'MAIL_HOST' => '127.0.0.1', 'MAIL_PORT' => (string) $port];
            $this->worker($row, $environment);
            $this->assertSame(0, $sink->wait());
            $this->assertSame('uncertain', $this->row($row)->state);
            $this->assertNull($this->row($row)->encrypted_payload);
            $this->recoveryFault('redis-restart');
            DB::select('SELECT app_reconcile_notifications()');
            $this->worker($row, $environment);
            $this->assertSame('uncertain', $this->row($row)->state);
        } finally {
            $sink->stop(0);
        }
    }

    public function test_real_dispatch_lease_expires_after_queue_loss_and_restarted_workers_commit_once(): void
    {
        $row = $this->invitation();
        $claimed = false;
        for ($i = 0; $i < 20 && ! $claimed; $i++) {
            $claimed = collect(DB::select('SELECT * FROM app_claim_notifications()'))->contains('id', $row->id);
        }
        $this->assertTrue($claimed);
        $this->recoveryFault('redis-loss');
        $this->assertNull(collect(DB::select('SELECT * FROM app_pending_notifications()'))->firstWhere('id', $row->id));
        $this->eventually(fn () => collect(DB::select('SELECT * FROM app_pending_notifications()'))->contains('id', $row->id),
            'Real dispatch lease did not expire.', seconds: 70);
        $this->worker($row);
        $this->assertSame('captured', $this->row($row)->state);
        $completed = $this->row($row)->completed_at;
        $this->worker($row);
        $this->assertSame($completed, $this->row($row)->completed_at);
    }

    public function test_downtime_across_dst_coalesces_one_durable_occurrence_without_fold_replay(): void
    {
        foreach ([['2026-03-01T14:00:00Z', '2026-03-10T14:00:00Z', '2026-03-15T13:00:00Z'],
            ['2026-10-25T13:00:00Z', '2026-11-03T14:00:00Z', '2026-11-08T14:00:00Z']] as [$before, $after, $next]) {
            $user = User::factory()->create();
            $org = app(Workspaces::class)->create($user->id, 'DST recovery');
            $this->actingAs($user)->withSession(['active_org_id' => $org]);
            try {
                CarbonImmutable::setTestNow($before);
                $this->putJson('/automation/preferences', ['version' => 0, 'timezone' => 'America/New_York',
                    'reminders' => false, 'weekly_digest' => true, 'follow_up_drafts' => false])->assertOk();
                $id = $this->postJson('/automation/schedules', ['name' => 'DST digest', 'kind' => 'weekly_digest',
                    'weekday' => 7, 'local_time' => '09:00', 'consent' => true])->assertCreated()->json('id');
                CarbonImmutable::setTestNow($after);
                $this->recoveryFault('redis-loss');
                app(BusinessSchedules::class)->materialize($user->id, $org, $id);
                app(BusinessSchedules::class)->materialize($user->id, $org, $id);
                $this->assertSame(1, $this->control->table('schedule_runs')->where('schedule_id', $id)->count());
                $this->assertSame($next, CarbonImmutable::parse($this->control->table('business_schedules')->where('id', $id)->value('next_due_at'))->format('Y-m-d\TH:i:s\Z'));
            } finally {
                CarbonImmutable::setTestNow();
            }
        }
    }
}
