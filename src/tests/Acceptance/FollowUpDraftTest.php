<?php

namespace Tests\Acceptance;

use App\Jobs\PrepareFollowUp;
use App\Models\User;
use App\Services\BusinessSchedules;
use App\Services\FollowUpDrafts;
use App\Services\Workspaces;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;

class FollowUpDraftTest extends AcceptanceCase
{
    private function setupDraft(): array
    {
        $user = User::factory()->create();
        $org = app(Workspaces::class)->create($user->id, 'Synthetic follow-ups');
        $this->actingAs($user)->withSession(['active_org_id' => $org]);
        $lead = $this->postJson('/pipeline', ['brand_name' => 'Private brand', 'currency' => 'PHP', 'value_minor' => '0', 'notes' => 'Never disclose this note'])->assertCreated()->json('id');
        $data = ['pipeline_entry_id' => $lead, 'recipient' => 'recipient@example.test', 'subject' => 'Review collaboration', 'body' => 'A manually written message.'];
        $id = $this->postJson('/automation/drafts', $data)->assertCreated()->json('id');

        return [$user, $org, $lead, $id, $data];
    }

    private function row(string $id): object
    {
        return $this->control->table('follow_up_drafts')->where('id', $id)->first();
    }

    private function scheduled(): array
    {
        [$user, $org, $lead] = $this->setupDraft();
        $this->putJson('/automation/preferences', ['version' => 0, 'timezone' => 'UTC', 'reminders' => false, 'weekly_digest' => false, 'follow_up_drafts' => true])->assertOk();
        $schedule = $this->postJson('/automation/schedules', ['name' => 'Prepare follow-up', 'kind' => 'follow_up_draft', 'subject_id' => $lead, 'consent' => true, 'due_at' => now()->addHour()->format('Y-m-d\TH:i:sP')])->assertCreated()->json('id');
        $this->control->table('business_schedules')->where('id', $schedule)->update(['next_due_at' => now()->subMinute()]);
        app(BusinessSchedules::class)->materialize($user->id, $org, $schedule);
        $run = $this->control->table('schedule_runs')->where('schedule_id', $schedule)->value('id');

        return [$user, $org, $lead, $schedule, $run];
    }

    public function test_manual_path_requires_exact_approval_and_never_sends(): void
    {
        [, $org, , $id, $data] = $this->setupDraft();
        $this->getJson('/automation/drafts/'.$id.'/handoff?version=1')->assertConflict();
        $this->postJson('/automation/drafts', [...$data, 'recipient' => "one@example.test\r\nBcc: two@example.test"])->assertUnprocessable();
        $this->postJson('/automation/drafts', [...$data, 'subject' => "Hello\nInjected"])->assertUnprocessable();
        $this->postJson('/automation/drafts/'.$id.'/approve', ['version' => 1])->assertOk()->assertJsonPath('message', 'Approved for manual sending; no email sent');
        $this->get('/automation/drafts/'.$id.'/handoff?version=2')->assertOk()->assertHeader('Content-Disposition', 'attachment; filename="follow-up.txt"')->assertSee('To: recipient@example.test')->assertDontSee('Never disclose');
        $this->postJson('/automation/drafts/'.$id.'/discard', ['version' => 1])->assertConflict();
        unset($data['pipeline_entry_id']);
        $this->patchJson('/automation/drafts/'.$id, [...$data, 'version' => 2, 'body' => 'Changed message'])->assertOk();
        $this->assertSame('draft', $this->row($id)->state);
        $this->assertNull($this->row($id)->approved_version);
        $this->getJson('/automation/drafts/'.$id.'/handoff?version=2')->assertConflict();
        $this->getJson('/automation/drafts/'.$id.'/handoff?version=3')->assertConflict();
        $this->postJson('/automation/drafts/'.$id.'/approve', ['version' => 3])->assertOk();
        $this->postJson('/automation/drafts/'.$id.'/discard', ['version' => 4])->assertOk();
        $this->postJson('/automation/drafts/'.$id.'/approve', ['version' => 5])->assertUnprocessable();
        $this->patchJson('/automation/drafts/'.$id, [...$data, 'version' => 5])->assertUnprocessable();
        $this->assertSame(0, $this->control->table('notification_outbox')->where('org_id', $org)->count());
    }

    public function test_actor_workspace_viewer_revocation_and_capability_isolation(): void
    {
        [$user, $org, , $id, $data] = $this->setupDraft();
        $other = User::factory()->create();
        $this->control->table('memberships')->insert(['org_id' => $org, 'user_id' => $other->id, 'role' => 'admin']);
        $this->actingAs($other)->withSession(['active_org_id' => $org]);
        $this->getJson('/automation')->assertOk()->assertJsonCount(0, 'drafts');
        $this->postJson('/automation/drafts/'.$id.'/approve', ['version' => 1])->assertNotFound();
        app(TenantContext::class)->run($other->id, $org, function () use ($id): void {
            $this->assertSame(0, DB::table('follow_up_drafts')->where('id', $id)->count());
            $this->assertSame(0, DB::table('follow_up_drafts')->where('id', $id)->update(['body' => 'forged']));
        });
        $foreign = app(Workspaces::class)->create($other->id, 'Foreign follow-up');
        $this->withSession(['active_org_id' => $foreign]);
        $this->postJson('/automation/drafts', $data)->assertNotFound();
        $this->getJson('/automation/drafts/'.$id.'/handoff?version=1')->assertNotFound();
        $this->actingAs($user)->withSession(['active_org_id' => $org]);
        $this->control->table('memberships')->where('org_id', $org)->where('user_id', $user->id)->update(['role' => 'viewer']);
        $this->getJson('/automation')->assertOk()->assertJsonCount(0, 'drafts');
        $this->postJson('/automation/drafts/'.$id.'/approve', ['version' => 1])->assertForbidden();
        $this->control->table('memberships')->where('org_id', $org)->where('user_id', $user->id)->update(['revoked_at' => now()]);
        $this->getJson('/automation')->assertForbidden();
        app(TenantContext::class)->run(0, null, fn () => $this->assertSame(0, DB::table('follow_up_drafts')->count()), requireMembership: false);
    }

    public function test_current_lead_verification_and_entitlement_required_for_download(): void
    {
        foreach (['lead', 'verification', 'entitlement'] as $change) {
            [$user, $org, $lead, $id] = $this->setupDraft();
            $this->postJson('/automation/drafts/'.$id.'/approve', ['version' => 1])->assertOk();
            if ($change === 'lead') {
                $this->control->table('pipeline_entries')->where('id', $lead)->increment('version');
            } elseif ($change === 'verification') {
                $this->control->table('users')->where('id', $user->id)->update(['email_verified_at' => null]);
                $this->actingAs($user->fresh());
            } else {
                $plan = (string) Str::uuid();
                $this->control->table('pilot_plan_versions')->insert(['id' => $plan, 'name' => $plan, 'version' => 1, 'quotas' => '{}']);
                $this->control->table('pilot_assignments')->insert(['id' => (string) Str::uuid(), 'org_id' => $org, 'plan_id' => $plan, 'version' => 1, 'starts_at' => now()->subDays(2), 'ends_at' => now()->subDay()]);
            }
            $this->assertContains($this->getJson('/automation/drafts/'.$id.'/handoff?version=2')->status(), [403, 409]);
            $this->assertSame(2, $this->row($id)->version);
        }
    }

    public function test_preparation_is_atomic_private_replay_safe_and_reconstructed_after_queue_loss(): void
    {
        [$user, $org, $lead, $schedule, $run] = $this->scheduled();
        Queue::fake();
        app(FollowUpDrafts::class)->dispatch();
        Queue::assertPushed(PrepareFollowUp::class, fn ($job) => $job->runId === $run);
        app(FollowUpDrafts::class)->dispatch();
        try {
            app(BusinessSchedules::class)->execute($user->id, $org, $schedule, $run, function () use ($user, $org, $lead): void {
                app(FollowUpDrafts::class)->save($user->id, $org, ['pipeline_entry_id' => $lead, 'recipient' => 'rollback@example.test', 'subject' => 'Rollback', 'body' => 'Rollback']);
                throw new RuntimeException('roll back');
            });
        } catch (RuntimeException $e) {
            $this->assertSame('roll back', $e->getMessage());
        }
        $this->assertSame('ready', $this->control->table('schedule_runs')->where('id', $run)->value('state'));
        $this->assertSame(0, $this->control->table('follow_up_drafts')->where('subject', 'Rollback')->count());
        app(FollowUpDrafts::class)->consume($user->id, $org, $schedule, $run);
        app(FollowUpDrafts::class)->consume($user->id, $org, $schedule, $run);
        $this->assertSame(1, $this->control->table('follow_up_drafts')->where('schedule_run_id', $run)->count());
        $draft = $this->control->table('follow_up_drafts')->where('schedule_run_id', $run)->first();
        $this->assertSame('', $draft->recipient);
        $this->assertStringNotContainsString('Private brand', $draft->body);
        $this->assertStringNotContainsString('Never disclose', $draft->body);
        $this->postJson('/automation/drafts/'.$draft->id.'/approve', ['version' => 1])->assertUnprocessable()->assertJsonValidationErrors('recipient');
        $this->patchJson('/automation/drafts/'.$draft->id, ['version' => 1, 'recipient' => 'approved@example.test', 'subject' => 'Follow-up', 'body' => 'Reviewed manually'])->assertOk();
        $this->postJson('/automation/drafts/'.$draft->id.'/approve', ['version' => 2])->assertOk();
        $this->deleteJson('/automation/schedules/'.$schedule, ['version' => 1])->assertOk();
        $this->getJson('/automation/drafts/'.$draft->id.'/handoff?version=3')->assertConflict();
        $this->assertSame(0, $this->control->table('notification_outbox')->where('org_id', $org)->count());
    }

    public function test_revoked_schedule_cannot_prepare_a_draft(): void
    {
        [$user, $org, , $schedule, $run] = $this->scheduled();
        $this->deleteJson('/automation/schedules/'.$schedule, ['version' => 1])->assertOk();
        app(FollowUpDrafts::class)->consume($user->id, $org, $schedule, $run);
        $this->assertSame(0, $this->control->table('follow_up_drafts')->where('schedule_run_id', $run)->count());
        $this->assertSame('cancelled', $this->control->table('schedule_runs')->where('id', $run)->value('state'));
    }
}
