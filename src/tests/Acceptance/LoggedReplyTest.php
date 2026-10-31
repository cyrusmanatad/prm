<?php

namespace Tests\Acceptance;

use App\Jobs\PrepareFollowUp;
use App\Models\User;
use App\Services\BusinessSchedules;
use App\Services\FollowUpDrafts;
use App\Services\Pipeline;
use App\Services\Workspaces;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Symfony\Component\Process\Process;

class LoggedReplyTest extends AcceptanceCase
{
    private function setupReply(): array
    {
        $user = User::factory()->create();
        $org = app(Workspaces::class)->create($user->id, 'Synthetic reply cancellation');
        $this->actingAs($user)->withSession(['active_org_id' => $org]);
        $lead = $this->postJson('/pipeline', ['brand_name' => 'Reply lead', 'currency' => 'PHP', 'value_minor' => '0', 'next_action_due' => now()->addDay()->toIso8601String()])->assertCreated()->json('id');
        $content = ['pipeline_entry_id' => $lead, 'recipient' => 'private@example.test', 'subject' => 'Private follow-up', 'body' => 'Private message'];
        $draft = $this->postJson('/automation/drafts', $content)->assertCreated()->json('id');
        $this->postJson('/automation/drafts/'.$draft.'/approve', ['version' => 1])->assertOk();
        $this->putJson('/automation/preferences', ['version' => 0, 'timezone' => 'UTC', 'reminders' => true, 'weekly_digest' => true, 'follow_up_drafts' => true])->assertOk();
        $schedule = $this->postJson('/automation/schedules', ['name' => 'Old follow-up', 'kind' => 'follow_up_draft', 'subject_id' => $lead, 'consent' => true, 'due_at' => now()->addHour()->toIso8601String()])->assertCreated()->json('id');
        $this->control->table('business_schedules')->where('id', $schedule)->update(['next_due_at' => now()->subMinute()]);
        app(BusinessSchedules::class)->materialize($user->id, $org, $schedule);
        $run = $this->control->table('schedule_runs')->where('schedule_id', $schedule)->value('id');

        return [$user, $org, $lead, $draft, $schedule, $run, $content];
    }

    private function reply(string $lead, int $version = 1): TestResponse
    {
        return $this->postJson('/pipeline/'.$lead.'/activities', ['version' => $version, 'kind' => 'reply', 'body' => 'Received a reply outside WeavDash.']);
    }

    public function test_reply_cancels_approved_and_prepared_drafts_and_real_queued_replays(): void
    {
        [$user, $org, $lead, $draft, $schedule, $run, $content] = $this->setupReply();
        $queue = Queue::connection('redis');
        $name = 'reply-'.$run;
        try {
            $queue->push(new PrepareFollowUp($user->id, $org, $schedule, $run), '', $name);
            $this->reply($lead)->assertCreated();
            for ($i = 0; $i < 2; $i++) {
                $this->assertSame(0, Artisan::call('queue:work', ['connection' => 'redis', '--once' => true, '--queue' => $name]));
                $queue->push(new PrepareFollowUp($user->id, $org, $schedule, $run), '', $name);
            }
            app(BusinessSchedules::class)->materialize($user->id, $org, $schedule);
            $this->assertSame('cancelled', $this->control->table('schedule_runs')->where('id', $run)->value('state'));
            $this->assertFalse($this->control->table('business_schedules')->where('id', $schedule)->value('enabled'));
            $this->assertNull($this->control->table('pipeline_entries')->where('id', $lead)->value('next_action_due'));
            $this->assertSame(1, $this->control->table('follow_up_drafts')->where('org_id', $org)->count());
            $row = $this->control->table('follow_up_drafts')->where('id', $draft)->first();
            $this->assertSame('cancelled', $row->state);
            $this->assertNull($row->approved_version);
            $this->getJson('/automation/drafts/'.$draft.'/handoff?version=2')->assertConflict();
            $this->getJson('/automation/drafts/'.$draft.'/handoff?version=3')->assertUnprocessable();
            $this->postJson('/automation/drafts/'.$draft.'/approve', ['version' => 3])->assertUnprocessable();
            $this->patchJson('/automation/drafts/'.$draft, [...$content, 'version' => 3])->assertUnprocessable();
            $this->reply($lead)->assertConflict();
            $this->assertSame(1, $this->control->table('pipeline_activities')->where('pipeline_entry_id', $lead)->where('kind', 'reply')->count());
            // A reply is a cutoff, not a permanent ban: explicitly create new work.
            $this->postJson('/automation/drafts', $content)->assertCreated();
            $this->assertSame(0, $this->control->table('notification_outbox')->where('org_id', $org)->count());
        } finally {
            $queue->clear($name);
        }
    }

    public function test_reply_cancels_other_members_private_work_without_disclosing_it(): void
    {
        [$user, $org, $lead, $draft, $schedule, $run] = $this->setupReply();
        app(FollowUpDrafts::class)->consume($user->id, $org, $schedule, $run);
        $peer = User::factory()->create();
        $this->control->table('memberships')->insert(['org_id' => $org, 'user_id' => $peer->id, 'role' => 'member']);
        $this->actingAs($peer)->withSession(['active_org_id' => $org]);
        $this->getJson('/automation')->assertOk()->assertJsonCount(0, 'drafts');
        $this->reply($lead)->assertCreated()->assertDontSee('private@example.test');
        $this->getJson('/automation')->assertOk()->assertJsonCount(0, 'drafts');
        $this->assertSame(2, $this->control->table('follow_up_drafts')->where('org_id', $org)->where('state', 'cancelled')->count());
        $this->assertSame('completed', $this->control->table('schedule_runs')->where('id', $run)->value('state'));
        $this->getJson('/automation/drafts/'.$draft.'/handoff?version=3')->assertNotFound();
    }

    public function test_other_activity_and_unrelated_leads_and_reminders_are_preserved(): void
    {
        [, $org, $lead, $draft] = $this->setupReply();
        $other = $this->postJson('/pipeline', ['brand_name' => 'Other lead', 'currency' => 'PHP', 'value_minor' => '0', 'next_action_due' => now()->addDay()->toIso8601String()])->assertCreated()->json('id');
        $reminder = $this->postJson('/reminders', ['pipeline_entry_id' => $lead, 'title' => 'Contract review', 'due_at' => now()->addDay()->toIso8601String()])->assertCreated()->json('id');
        $schedule = $this->postJson('/automation/schedules', ['name' => 'Contract reminder', 'kind' => 'reminder', 'subject_id' => $reminder, 'consent' => true, 'due_at' => now()->addHour()->toIso8601String()])->assertCreated()->json('id');
        $this->postJson('/pipeline/'.$lead.'/activities', ['version' => 1, 'kind' => 'email', 'body' => 'An outbound email was sent manually.'])->assertCreated();
        $this->assertSame('approved', $this->control->table('follow_up_drafts')->where('id', $draft)->value('state'));
        $this->reply($lead, 2)->assertCreated();
        $this->assertNotNull($this->control->table('pipeline_entries')->where('id', $other)->value('next_action_due'));
        $this->assertNull($this->control->table('reminders')->where('id', $reminder)->value('completed_at'));
        $this->assertTrue($this->control->table('business_schedules')->where('id', $schedule)->value('enabled'));
        $this->assertSame(3, $this->control->table('pipeline_entries')->where('id', $lead)->value('version'));
    }

    public function test_denied_stale_and_rolled_back_replies_have_no_cancellation_side_effects(): void
    {
        [$user, $org, $lead, $draft] = $this->setupReply();
        $this->reply($lead, 99)->assertConflict();
        $this->postJson('/pipeline/'.$lead.'/activities', ['version' => 1, 'kind' => 'reply', 'body' => ''])->assertUnprocessable();
        try {
            app(TenantContext::class)->run($user->id, $org, function () use ($user, $org, $lead): void {
                app(Pipeline::class)->addActivity($user->id, $org, $lead, 1, 'reply', 'Rollback probe');
                throw new RuntimeException('Rollback reply');
            });
        } catch (RuntimeException $e) {
            $this->assertSame('Rollback reply', $e->getMessage());
        }
        $this->control->table('memberships')->where('org_id', $org)->update(['role' => 'viewer']);
        $this->reply($lead)->assertForbidden();
        $this->control->table('memberships')->where('org_id', $org)->update(['role' => 'owner', 'revoked_at' => now()]);
        $this->reply($lead)->assertForbidden();
        $foreign = app(Workspaces::class)->create($user->id, 'Foreign reply workspace');
        $this->withSession(['active_org_id' => $foreign]);
        $this->reply($lead)->assertNotFound();
        $this->assertSame('approved', $this->control->table('follow_up_drafts')->where('id', $draft)->value('state'));
        $this->assertSame(0, $this->control->table('pipeline_activities')->where('pipeline_entry_id', $lead)->where('kind', 'reply')->count());
        $this->assertSame(0, $this->control->table('audit_logs')->where('org_id', $org)->where('event', 'pipeline.reply')->count());
        $this->assertNotNull($this->control->table('pipeline_entries')->where('id', $lead)->value('next_action_due'));
        $this->assertFalse($this->control->selectOne("SELECT has_function_privilege('weavdash_app','app_cancel_logged_reply()','EXECUTE') AS ok")->ok);
    }

    public function test_reply_and_preparation_serialize_in_both_lock_orders(): void
    {
        foreach (['reply-first', 'prepare-first'] as $order) {
            [$user, $org, $lead, , $schedule, $run] = $this->setupReply();
            $probe = null;
            $spawn = function (string $action) use ($user, $org, $lead, $schedule, $run, &$probe): void {
                $probe = new Process([PHP_BINARY, 'tests/Fixtures/reply-probe.php', $action, (string) $user->id, $org, $lead, $schedule, $run], base_path(), ['TEST_MIGRATION_DB_PASSWORD' => false]);
                $probe->start();
                $this->eventually(fn () => str_contains($probe->getOutput(), "\n"), 'Reply probe connected');
                $this->assertBlocked($probe);
            };
            try {
                if ($order === 'reply-first') {
                    app(TenantContext::class)->run($user->id, $org, function () use ($user, $org, $lead, $spawn): void {
                        app(Pipeline::class)->addActivity($user->id, $org, $lead, 1, 'reply', 'Concurrent reply');
                        $spawn('prepare');
                    });
                } else {
                    // Observe the real consumer after its completion write but before commit.
                    $armed = true;
                    DB::listen(function ($query) use (&$armed, $spawn): void {
                        if ($armed && str_starts_with($query->sql, 'update "schedule_runs"')) {
                            $armed = false;
                            $spawn('reply');
                        }
                    });
                    try {
                        app(FollowUpDrafts::class)->consume($user->id, $org, $schedule, $run);
                    } finally {
                        $armed = false;
                    }
                }
                $this->assertTrue($this->probeResult($probe)['done']);
                $this->assertSame(0, $this->control->table('follow_up_drafts')->where('org_id', $org)->whereIn('state', ['draft', 'approved'])->count());
                $this->assertSame($order === 'reply-first' ? 'cancelled' : 'completed', $this->control->table('schedule_runs')->where('id', $run)->value('state'));
            } finally {
                $probe?->stop(0);
            }
        }
    }

    public function test_reply_and_approved_download_serialize_in_both_lock_orders(): void
    {
        foreach (['reply-first', 'handoff-first'] as $order) {
            [$user, $org, $lead, $draft, $schedule, $run] = $this->setupReply();
            $probe = null;
            try {
                app(TenantContext::class)->run($user->id, $org, function () use ($order, $user, $org, $lead, $draft, $schedule, $run, &$probe): void {
                    if ($order === 'reply-first') {
                        app(Pipeline::class)->addActivity($user->id, $org, $lead, 1, 'reply', 'Concurrent reply');
                    } else {
                        $this->assertStringContainsString('To: private@example.test', app(FollowUpDrafts::class)->transition($user->id, $org, $draft, 2, 'handoff'));
                    }
                    $probe = new Process([PHP_BINARY, 'tests/Fixtures/reply-probe.php', $order === 'reply-first' ? 'handoff' : 'reply', (string) $user->id, $org, $lead, $order === 'reply-first' ? $draft : $schedule, $run], base_path(), ['TEST_MIGRATION_DB_PASSWORD' => false]);
                    $probe->start();
                    $this->eventually(fn () => str_contains($probe->getOutput(), "\n"), 'Handoff probe connected');
                    $this->assertBlocked($probe);
                });
                $result = $this->probeResult($probe);
                if ($order === 'reply-first') {
                    $this->assertFalse($result['allowed']);
                    $this->assertSame(409, $result['status']);
                } else {
                    $this->assertTrue($result['done']);
                }
                $this->assertSame('cancelled', $this->control->table('follow_up_drafts')->where('id', $draft)->value('state'));
            } finally {
                $probe?->stop(0);
            }
        }
    }
}
