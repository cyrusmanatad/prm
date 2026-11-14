<?php

namespace Tests\Acceptance;

use App\Models\User;
use App\Services\BusinessSchedules;
use App\Services\FollowUpDrafts;
use App\Services\Pipeline;
use App\Services\Workspaces;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;

class FollowUpApprovalTest extends AcceptanceCase
{
    private function approved(bool $scheduled = false): array
    {
        $user = User::factory()->create();
        $org = app(Workspaces::class)->create($user->id, 'Synthetic approval basis');
        $this->actingAs($user)->withSession(['active_org_id' => $org]);
        $lead = $this->postJson('/pipeline', ['brand_name' => 'Approval lead', 'currency' => 'PHP', 'value_minor' => '0'])->assertCreated()->json('id');
        $schedule = null;
        if ($scheduled) {
            $this->putJson('/automation/preferences', ['version' => 0, 'timezone' => 'UTC', 'reminders' => false, 'weekly_digest' => false, 'follow_up_drafts' => true])->assertOk();
            $schedule = $this->postJson('/automation/schedules', ['name' => 'Approval basis', 'kind' => 'follow_up_draft', 'subject_id' => $lead, 'consent' => true, 'due_at' => now()->addHour()->toIso8601String()])->assertCreated()->json('id');
            $this->control->table('business_schedules')->where('id', $schedule)->update(['next_due_at' => now()->subMinute()]);
            app(BusinessSchedules::class)->materialize($user->id, $org, $schedule);
            $run = $this->control->table('schedule_runs')->where('schedule_id', $schedule)->value('id');
            app(FollowUpDrafts::class)->consume($user->id, $org, $schedule, $run);
            $id = $this->control->table('follow_up_drafts')->where('schedule_run_id', $run)->value('id');
            $this->patchJson('/automation/drafts/'.$id, ['version' => 1, 'recipient' => 'recipient@example.test', 'subject' => 'Reviewed message', 'body' => 'Reviewed content'])->assertOk();
            $version = 2;
        } else {
            $id = $this->postJson('/automation/drafts', ['pipeline_entry_id' => $lead, 'recipient' => 'recipient@example.test', 'subject' => 'Reviewed message', 'body' => 'Reviewed content'])->assertCreated()->json('id');
            $version = 1;
        }
        $this->postJson('/automation/drafts/'.$id.'/approve', ['version' => $version])->assertOk();

        return [$user, $org, $lead, $id, $schedule, $version + 1];
    }

    private function assertInvalidated(string $id, int $oldVersion): void
    {
        $row = $this->control->table('follow_up_drafts')->where('id', $id)->first();
        $this->assertSame('discarded', $row->state);
        $this->assertSame($oldVersion + 1, $row->version);
        $this->assertNull($row->approved_version);
        $this->assertNull($row->approved_at);
        $this->assertSame(1, $this->control->table('audit_logs')->where('subject_id', $id)->where('event', 'follow_up.invalidated')->count());
        $this->getJson('/automation/drafts/'.$id.'/handoff?version='.$oldVersion)->assertConflict();
    }

    public function test_lead_change_invalidates_all_owners_without_exposing_private_content_and_rolls_back(): void
    {
        [$user, $org, $lead, $id, , $version] = $this->approved();
        $peer = User::factory()->create();
        $this->control->table('memberships')->insert(['org_id' => $org, 'user_id' => $peer->id, 'role' => 'member']);
        $this->actingAs($peer)->withSession(['active_org_id' => $org]);
        $this->getJson('/automation')->assertOk()->assertJsonCount(0, 'drafts');
        try {
            $this->control->transaction(function () use ($lead): void {
                $this->control->table('pipeline_entries')->where('id', $lead)->increment('version');
                $this->control->statement('SET CONSTRAINTS ALL IMMEDIATE');
                throw new RuntimeException('rollback basis');
            });
        } catch (RuntimeException $e) {
            $this->assertSame('rollback basis', $e->getMessage());
        }
        $this->assertSame('approved', $this->control->table('follow_up_drafts')->where('id', $id)->value('state'));
        $this->patchJson('/pipeline/'.$lead, ['version' => 1, 'brand_name' => 'Updated by peer', 'currency' => 'PHP', 'value_minor' => '0'])->assertOk();
        $this->getJson('/automation')->assertOk()->assertJsonCount(0, 'drafts');
        $this->getJson('/automation/drafts/'.$id.'/handoff?version='.$version)->assertNotFound();
        $this->actingAs($user)->withSession(['active_org_id' => $org]);
        $this->assertInvalidated($id, $version);
        $this->postJson('/automation/drafts/'.$id.'/approve', ['version' => $version + 1])->assertUnprocessable();
    }

    public function test_withdraw_restore_and_rule_changes_never_restore_scheduled_approval(): void
    {
        foreach (['schedule', 'preference', 'rule', 'run'] as $basis) {
            [, , , $id, $schedule, $version] = $this->approved(true);
            if ($basis === 'schedule') {
                $this->deleteJson('/automation/schedules/'.$schedule, ['version' => 1])->assertOk();
            } elseif ($basis === 'preference') {
                $this->putJson('/automation/preferences', ['version' => 2, 'timezone' => 'UTC', 'reminders' => false, 'weekly_digest' => false, 'follow_up_drafts' => false])->assertOk();
                $this->putJson('/automation/preferences', ['version' => 3, 'timezone' => 'UTC', 'reminders' => false, 'weekly_digest' => false, 'follow_up_drafts' => true])->assertOk();
            } elseif ($basis === 'rule') {
                $this->control->table('business_schedules')->where('id', $schedule)->increment('version');
            } else {
                $this->control->table('schedule_runs')->where('schedule_id', $schedule)->update(['state' => 'cancelled']);
            }
            $this->recoveryFault('redis-restart');
            $this->assertInvalidated($id, $version);
            app(FollowUpDrafts::class)->dispatch();
            $this->assertSame($version + 1, $this->control->table('follow_up_drafts')->where('id', $id)->value('version'));
        }
    }

    public function test_membership_and_identity_restoration_do_not_revive_approvals(): void
    {
        foreach (['role', 'revoked', 'removed', 'verification', 'email'] as $basis) {
            [$user, $org, , $id, , $version] = $this->approved();
            if (in_array($basis, ['role', 'revoked', 'removed'], true)) {
                $this->control->transaction(function () use ($user, $org, $basis): void {
                    $membership = $this->control->table('memberships')->where('org_id', $org)->where('user_id', $user->id);
                    if ($basis === 'removed') {
                        $membership->delete();
                        $this->control->table('memberships')->insert(['org_id' => $org, 'user_id' => $user->id, 'role' => 'owner']);
                    } else {
                        $membership->update($basis === 'role' ? ['role' => 'viewer'] : ['revoked_at' => now()]);
                        $membership->update(['role' => 'owner', 'revoked_at' => null]);
                    }
                });
            } else {
                $this->control->table('users')->where('id', $user->id)->update($basis === 'email' ? ['email' => 'changed-'.$user->id.'@example.test'] : ['email_verified_at' => null]);
                $this->control->table('users')->where('id', $user->id)->update(['email' => $user->email, 'email_verified_at' => now()]);
            }
            $this->recoveryFault('redis-restart');
            $this->assertInvalidated($id, $version);
        }
    }

    public function test_unrelated_workspace_approvals_remain_valid(): void
    {
        [, , $lead, $id] = $this->approved();
        [, , , $other] = $this->approved();
        $this->control->table('pipeline_entries')->where('id', $lead)->increment('version');
        $this->assertSame('discarded', $this->control->table('follow_up_drafts')->where('id', $id)->value('state'));
        $this->assertSame('approved', $this->control->table('follow_up_drafts')->where('id', $other)->value('state'));
        $this->get('/automation/drafts/'.$other.'/handoff?version=2')->assertOk();
    }

    public function test_recipient_or_content_changes_cannot_keep_approval_even_with_direct_runtime_sql(): void
    {
        foreach (['recipient' => 'different@example.test', 'subject' => 'Different subject', 'body' => 'Different body'] as $field => $value) {
            [$user, $org, , $id, , $version] = $this->approved();
            app(TenantContext::class)->run($user->id, $org, fn () => DB::table('follow_up_drafts')->where('id', $id)->update([$field => $value]));
            $row = $this->control->table('follow_up_drafts')->where('id', $id)->first();
            $this->assertSame('draft', $row->state);
            $this->assertSame($version + 1, $row->version);
            $this->assertNull($row->approved_version);
            $this->getJson('/automation/drafts/'.$id.'/handoff?version='.$version)->assertConflict();
        }
    }

    public function test_expiry_reconciles_without_duplicate_audits_or_cross_workspace_disclosure(): void
    {
        [$user, $org, , $id, , $version] = $this->approved();
        $plan = (string) Str::uuid();
        $assignment = (string) Str::uuid();
        $this->control->table('pilot_plan_versions')->insert(['id' => $plan, 'name' => $plan, 'version' => 1, 'quotas' => '{}']);
        $this->control->table('pilot_assignments')->insert(['id' => $assignment, 'org_id' => $org, 'plan_id' => $plan, 'version' => 1, 'starts_at' => now()->subDay(), 'ends_at' => now()->addDay()]);
        // Assignment changes invalidate existing approval; explicitly create and approve fresh work.
        $id = $this->postJson('/automation/drafts', ['pipeline_entry_id' => $this->control->table('follow_up_drafts')->where('id', $id)->value('pipeline_entry_id'), 'recipient' => 'fresh@example.test', 'subject' => 'Expiry', 'body' => 'Expiry content'])->assertCreated()->json('id');
        $this->postJson('/automation/drafts/'.$id.'/approve', ['version' => 1])->assertOk();
        // Wait for actual PostgreSQL wall-clock expiry; no application clock fake is used.
        $this->control->table('pilot_assignments')->where('id', $assignment)->update(['ends_at' => $this->control->raw("clock_timestamp()+interval '2 seconds'")]);
        $id = $this->postJson('/automation/drafts', ['pipeline_entry_id' => $this->control->table('follow_up_drafts')->where('id', $id)->value('pipeline_entry_id'), 'recipient' => 'expiry@example.test', 'subject' => 'Expires', 'body' => 'Expiry'])->assertCreated()->json('id');
        $this->postJson('/automation/drafts/'.$id.'/approve', ['version' => 1])->assertOk();
        $this->eventually(fn () => $this->control->selectOne('SELECT clock_timestamp() >= ends_at AS expired FROM pilot_assignments WHERE id=?', [$assignment])->expired, 'Synthetic assignment expired', 4);
        app(FollowUpDrafts::class)->dispatch();
        app(FollowUpDrafts::class)->dispatch();
        $row = $this->control->table('follow_up_drafts')->where('id', $id)->first();
        $this->assertSame('discarded', $row->state);
        $this->assertSame(3, $row->version);
        $this->assertSame(1, $this->control->table('audit_logs')->where('subject_id', $id)->where('event', 'follow_up.invalidated')->count());
        $foreign = app(Workspaces::class)->create($user->id, 'Other workspace');
        app(TenantContext::class)->run($user->id, $foreign, fn () => $this->assertSame(0, DB::table('follow_up_drafts')->where('id', $id)->count()));
        foreach (['app_follow_up_basis(follow_up_drafts)', 'app_invalidate_follow_up_approvals()', 'app_follow_up_content_changed()'] as $function) {
            $this->assertFalse($this->control->selectOne("SELECT has_function_privilege('weavdash_app',?,'EXECUTE') AS ok", [$function])->ok);
        }
    }

    public function test_basis_changes_and_handoff_serialize_in_both_real_postgres_lock_orders(): void
    {
        foreach (['lead', 'schedule'] as $basis) {
            foreach (['basis-first', 'handoff-first'] as $order) {
                [$user, $org, $lead, $id, $schedule, $version] = $this->approved($basis === 'schedule');
                $probe = null;
                try {
                    app(TenantContext::class)->run($user->id, $org, function () use ($basis, $order, $user, $org, $lead, $id, $schedule, $version, &$probe): void {
                        if ($order === 'handoff-first') {
                            $this->assertStringContainsString('To: recipient@example.test', app(FollowUpDrafts::class)->transition($user->id, $org, $id, $version, 'handoff'));
                        } elseif ($basis === 'lead') {
                            app(Pipeline::class)->change($user->id, $org, $lead, 1, 'updated', ['brand_name' => 'Concurrent basis']);
                        } else {
                            app(BusinessSchedules::class)->revoke($user->id, $org, $schedule, 1);
                        }
                        $probe = new Process([PHP_BINARY, 'tests/Fixtures/approval-probe.php', $order === 'basis-first' ? 'handoff' : $basis, (string) $user->id, $org, $lead, $id, $schedule ?? '', (string) $version], base_path(), ['TEST_MIGRATION_DB_PASSWORD' => false]);
                        $probe->start();
                        $this->eventually(fn () => str_contains($probe->getOutput(), "\n"), 'Approval probe connected');
                        $this->assertBlocked($probe);
                    });
                    $result = $this->probeResult($probe);
                    $this->assertSame($order === 'handoff-first', $result['allowed']);
                    if ($order === 'basis-first') {
                        $this->assertSame(409, $result['status']);
                    }
                    $this->assertInvalidated($id, $version);
                } finally {
                    $probe?->stop(0);
                }
            }
        }
    }
}
