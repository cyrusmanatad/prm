<?php

namespace Tests\Acceptance;

use App\Jobs\MaterializeSchedule;
use App\Models\User;
use App\Services\Audit;
use App\Services\BusinessSchedules;
use App\Services\Workspaces;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;

class BusinessScheduleTest extends AcceptanceCase
{
    private function setupActor(): array
    {
        $user = User::factory()->create();
        $org = app(Workspaces::class)->create($user->id, 'Synthetic schedules');
        $this->actingAs($user)->withSession(['active_org_id' => $org]);

        return [$user, $org];
    }

    private function preferences(array $overrides = []): array
    {
        return [...['version' => 0, 'timezone' => 'Asia/Manila', 'reminders' => true, 'weekly_digest' => true, 'follow_up_drafts' => true], ...$overrides];
    }

    private function weekly(array $overrides = []): array
    {
        return [...['name' => 'Weekly review', 'kind' => 'weekly_digest', 'weekday' => 1, 'local_time' => '09:00', 'consent' => true], ...$overrides];
    }

    private function schedule(): array
    {
        [$user, $org] = $this->setupActor();
        $this->putJson('/automation/preferences', $this->preferences())->assertOk();
        $id = $this->postJson('/automation/schedules', $this->weekly())->assertCreated()->json('id');
        $this->control->table('business_schedules')->where('id', $id)->update(['next_due_at' => now()->subMinute()]);

        return [$user, $org, $id];
    }

    private function occurrence(string $id): object
    {
        return $this->control->table('schedule_runs')->where('schedule_id', $id)->first();
    }

    private function denied(callable $callback): void
    {
        try {
            $this->assertSame(0, DB::transaction($callback));
        } catch (QueryException $e) {
            $this->assertSame('42501', $e->errorInfo[0]);
        }
    }

    public function test_defaults_validation_explicit_consent_and_versioned_history(): void
    {
        [, $org] = $this->setupActor();
        $this->getJson('/automation')->assertOk()->assertJsonPath('preferences.version', 0)->assertJsonPath('preferences.weekly_digest', false);
        $this->postJson('/automation/schedules', $this->weekly())->assertUnprocessable()->assertJsonValidationErrors('consent');
        $this->getJson('/automation')->assertJsonPath('preferences.version', 0);
        $this->putJson('/automation/preferences', $this->preferences(['timezone' => '+08:00']))->assertUnprocessable()->assertJsonValidationErrors('timezone');
        $this->putJson('/automation/preferences', $this->preferences())->assertOk();
        $this->putJson('/automation/preferences', $this->preferences())->assertConflict()->assertJsonValidationErrors('version');
        $this->postJson('/automation/schedules', $this->weekly(['consent' => false]))->assertUnprocessable();
        $this->postJson('/automation/schedules', $this->weekly(['weekday' => 8]))->assertUnprocessable();
        $this->postJson('/automation/schedules', $this->weekly(['local_time' => '25:00']))->assertUnprocessable();
        $id = $this->postJson('/automation/schedules', $this->weekly(['actor_id' => 999, 'rule_version' => 99]))->assertCreated()->json('id');
        $this->postJson('/automation/schedules', $this->weekly())->assertUnprocessable()->assertJsonValidationErrors('kind');
        $this->patchJson('/automation/schedules/'.$id, $this->weekly(['version' => 99]))->assertConflict();
        $this->patchJson('/automation/schedules/'.$id, $this->weekly(['version' => 1, 'local_time' => '10:00']))->assertOk();
        $this->assertSame(5, $this->control->table('automation_consent_events')->where('org_id', $org)->count());
        $this->assertSame(1, $this->control->table('business_schedules')->where('id', $id)->value('rule_version'));
    }

    public function test_real_rls_isolates_actors_tenants_capabilities_and_append_only_consent(): void
    {
        [$user, $org, $id] = $this->schedule();
        $peer = User::factory()->create();
        $this->control->table('memberships')->insert(['org_id' => $org, 'user_id' => $peer->id, 'role' => 'admin']);
        foreach ([$peer->id, $user->id] as $actor) {
            $other = $actor === $user->id ? app(Workspaces::class)->create($user->id, 'Other workspace') : $org;
            app(TenantContext::class)->run($actor, $other, function () use ($id): void {
                foreach (['automation_preferences', 'business_schedules', 'schedule_runs', 'automation_consent_events'] as $table) {
                    $this->assertSame(0, DB::table($table)->count());
                }
                $this->denied(fn () => DB::table('business_schedules')->where('id', $id)->update(['enabled' => false]));
                $this->assertFalse(DB::selectOne('SELECT app_schedule_current(?::uuid) AS ok', [$id])->ok);
            });
        }
        app(TenantContext::class)->run($user->id, $org, function (): void {
            $this->denied(fn () => DB::table('automation_consent_events')->update(['granted' => false]));
            $this->denied(fn () => DB::table('automation_consent_events')->delete());
        });
        app(TenantContext::class)->run(0, null, function (): void {
            $this->assertSame(0, DB::table('business_schedules')->count());
        }, requireMembership: false);
        $this->assertSame(4, $this->control->selectOne("SELECT count(*) AS n FROM pg_class WHERE relname IN ('automation_preferences','business_schedules','schedule_runs','automation_consent_events') AND relrowsecurity AND relforcerowsecurity")->n);
        $this->assertFalse($this->control->selectOne("SELECT has_function_privilege('weavdash_app','app_schedule_basis(business_schedules)','EXECUTE') AS ok")->ok);
    }

    public function test_all_writer_roles_and_current_revocation_verification_and_entitlement_are_rechecked(): void
    {
        foreach (['owner', 'admin', 'manager', 'member', 'viewer', 'revoked', 'unverified', 'expired', 'future'] as $role) {
            [$user, $org, $id] = $this->schedule();
            $changes = match ($role) {
                'revoked' => ['revoked_at' => now()], default => ['role' => in_array($role, ['unverified', 'expired', 'future']) ? 'owner' : $role]
            };
            $this->control->table('memberships')->where('org_id', $org)->update($changes);
            if ($role === 'unverified') {
                $this->control->table('users')->where('id', $user->id)->update(['email_verified_at' => null]);
            }
            if (in_array($role, ['expired', 'future'])) {
                $plan = (string) Str::uuid();
                $this->control->table('pilot_plan_versions')->insert(['id' => $plan, 'name' => $plan, 'version' => 1, 'quotas' => '{}']);
                $this->control->table('pilot_assignments')->insert(['id' => (string) Str::uuid(), 'org_id' => $org, 'plan_id' => $plan, 'version' => 1,
                    'starts_at' => $role === 'future' ? now()->addDay() : now()->subDays(2), 'ends_at' => $role === 'expired' ? now()->subDay() : now()->addDays(2)]);
            }
            app(BusinessSchedules::class)->materialize($user->id, $org, $id);
            $allowed = in_array($role, ['owner', 'admin', 'manager', 'member']);
            $this->assertSame($allowed, $this->control->table('schedule_runs')->where('schedule_id', $id)->exists(), $role);
            if (! $allowed) {
                $this->putJson('/automation/preferences', $this->preferences(['version' => 2]))->assertStatus(in_array($role, ['expired', 'future']) ? 200 : 403);
            }
        }
    }

    public function test_consent_withdrawal_invalidates_runs_without_reenabling_on_opt_in(): void
    {
        [$user, $org, $id] = $this->schedule();
        app(BusinessSchedules::class)->materialize($user->id, $org, $id);
        $this->putJson('/automation/preferences', $this->preferences(['version' => 2, 'weekly_digest' => false]))->assertOk();
        $this->assertSame('cancelled', $this->occurrence($id)->state);
        $this->assertFalse($this->control->table('business_schedules')->where('id', $id)->value('enabled'));
        $this->putJson('/automation/preferences', $this->preferences(['version' => 3]))->assertOk();
        app(BusinessSchedules::class)->execute($user->id, $org, $id, $this->occurrence($id)->id, fn () => $this->fail('Withdrawn run executed'));
        $this->assertFalse($this->control->table('business_schedules')->where('id', $id)->value('enabled'));
        $this->patchJson('/automation/schedules/'.$id, $this->weekly(['version' => 2]))->assertOk();
        $this->assertTrue($this->control->table('business_schedules')->where('id', $id)->value('enabled'));
        $this->deleteJson('/automation/schedules/'.$id, ['version' => 3])->assertOk();
        $this->deleteJson('/automation/schedules/'.$id, ['version' => 3])->assertConflict();
    }

    public function test_timezone_changes_recalculate_weekly_times_and_invalidate_old_runs(): void
    {
        [$user, $org, $id] = $this->schedule();
        app(BusinessSchedules::class)->materialize($user->id, $org, $id);
        $this->putJson('/automation/preferences', $this->preferences(['version' => 2, 'timezone' => 'America/New_York']))->assertOk();
        $s = $this->control->table('business_schedules')->where('id', $id)->first();
        $this->assertSame('America/New_York', $s->timezone);
        $this->assertSame(2, $s->version);
        $this->assertSame(3, $s->preference_version);
        $this->assertSame('cancelled', $this->occurrence($id)->state);
        $this->assertSame('09:00', CarbonImmutable::parse($s->next_due_at)->setTimezone($s->timezone)->format('H:i'));
    }

    public function test_redis_loss_reconstructs_due_work_and_replay_materializes_exactly_one_run(): void
    {
        [$user, $org, $id] = $this->schedule();
        $queue = Queue::connection('redis');
        $name = 'schedule-'.$id;
        try {
            $queue->push(new MaterializeSchedule($user->id, $org, $id), '', $name);
            $queue->clear($name);
            $this->assertNotNull(collect(DB::select('SELECT * FROM app_due_schedules()'))->firstWhere('id', $id));
            for ($i = 0; $i < 2; $i++) {
                $queue->push(new MaterializeSchedule($user->id, $org, $id), '', $name);
                $this->assertSame(0, Artisan::call('queue:work', ['connection' => 'redis', '--once' => true, '--queue' => $name]));
            }
            $this->assertSame(1, $this->control->table('schedule_runs')->where('schedule_id', $id)->count());
            $this->assertNull(collect(DB::select('SELECT * FROM app_due_schedules()'))->firstWhere('id', $id));
        } finally {
            $queue->clear($name);
        }
    }

    public function test_execution_and_intent_are_atomic_and_deduplicated_with_fresh_authorization(): void
    {
        [$user, $org, $id] = $this->schedule();
        app(BusinessSchedules::class)->materialize($user->id, $org, $id);
        $run = $this->occurrence($id)->id;
        try {
            app(BusinessSchedules::class)->execute($user->id, $org, $id, $run, function () use ($id): void {
                app(Audit::class)->record((int) auth()->id(), session('active_org_id'), 'schedule.test.intent', $id);
                throw new RuntimeException('Rollback');
            });
        } catch (RuntimeException $e) {
            $this->assertSame('Rollback', $e->getMessage());
        }
        $this->assertSame('ready', $this->occurrence($id)->state);
        $this->assertSame(0, $this->control->table('audit_logs')->where('subject_id', $id)->where('event', 'schedule.test.intent')->count());
        $count = 0;
        for ($i = 0; $i < 2; $i++) {
            app(BusinessSchedules::class)->execute($user->id, $org, $id, $run, function () use (&$count): void {
                $count++;
            });
        }
        $this->assertSame(1, $count);
        $this->assertSame('completed', $this->occurrence($id)->state);
        [$user, $org, $id] = $this->schedule();
        app(BusinessSchedules::class)->materialize($user->id, $org, $id);
        $this->control->table('memberships')->where('org_id', $org)->update(['role' => 'viewer']);
        app(BusinessSchedules::class)->execute($user->id, $org, $id, $this->occurrence($id)->id, fn () => $this->fail('Demoted actor executed'));
        DB::select('SELECT app_reconcile_schedules()');
        $this->assertSame('cancelled', $this->occurrence($id)->state);
    }

    public function test_one_shot_subject_versions_prevent_stale_and_cross_tenant_work(): void
    {
        [$user, $org] = $this->setupActor();
        $this->putJson('/automation/preferences', $this->preferences())->assertOk();
        $reminder = $this->postJson('/reminders', ['title' => 'Review', 'due_at' => now()->addDay()->toIso8601String()])->assertCreated()->json('id');
        $data = ['name' => 'Reminder', 'kind' => 'reminder', 'subject_id' => $reminder, 'due_at' => now()->addHour()->format('Y-m-d\TH:i:sP'), 'consent' => true];
        $this->postJson('/automation/schedules', [...$data, 'due_at' => '2020-01-01T00:00:00+00:00'])->assertUnprocessable();
        $id = $this->postJson('/automation/schedules', $data)->assertCreated()->json('id');
        $this->control->table('business_schedules')->where('id', $id)->update(['next_due_at' => now()->subMinute()]);
        app(BusinessSchedules::class)->materialize($user->id, $org, $id);
        $this->assertNull($this->control->table('business_schedules')->where('id', $id)->value('next_due_at'));
        $this->postJson('/reminders/'.$reminder.'/snooze', ['version' => 1, 'due_at' => now()->addDays(3)->toIso8601String()])->assertOk();
        app(BusinessSchedules::class)->execute($user->id, $org, $id, $this->occurrence($id)->id, fn () => $this->fail('Stale reminder executed'));
        $this->assertSame('cancelled', $this->occurrence($id)->state);
        [$other, $otherOrg] = $this->setupActor();
        $this->putJson('/automation/preferences', $this->preferences())->assertOk();
        $this->postJson('/automation/schedules', $data)->assertNotFound();
        $this->assertSame(0, $this->control->table('business_schedules')->where('org_id', $otherOrg)->count());
    }

    public function test_consent_and_execution_races_serialize_on_real_postgresql_locks(): void
    {
        foreach (['withdraw-first', 'execute-first'] as $order) {
            [$user, $org, $id] = $this->schedule();
            app(BusinessSchedules::class)->materialize($user->id, $org, $id);
            $run = $this->occurrence($id)->id;
            $probe = null;
            $spawn = function (string $action) use ($user, $org, $id, $run, &$probe): void {
                $probe = new Process([PHP_BINARY, 'tests/Fixtures/schedule-probe.php', $action, (string) $user->id, $org, $id, $run], base_path(), ['TEST_MIGRATION_DB_PASSWORD' => false]);
                $probe->start();
                $this->eventually(fn () => str_contains($probe->getOutput(), "\n"), 'Schedule probe connected');
                $this->assertBlocked($probe);
            };
            try {
                if ($order === 'withdraw-first') {
                    app(TenantContext::class)->run($user->id, $org, function () use ($user, $org, $id, $spawn): void {
                        app(BusinessSchedules::class)->revoke($user->id, $org, $id, 1);
                        $spawn('execute');
                    });
                } else {
                    app(BusinessSchedules::class)->execute($user->id, $org, $id, $run, function () use ($user, $org, $id, $spawn): void {
                        app(Audit::class)->record($user->id, $org, 'schedule.concurrent.intent', $id);
                        $spawn('revoke');
                    });
                }
                $this->assertSame($order === 'withdraw-first' ? 'cancelled' : 'completed', $this->probeResult($probe)['state']);
                $this->assertSame($order === 'withdraw-first' ? 0 : 1, $this->control->table('audit_logs')->where('subject_id', $id)->where('event', 'schedule.concurrent.intent')->count());
            } finally {
                $probe?->stop(0);
            }
        }
    }

    public function test_execution_rechecks_all_current_bases_and_rejected_writes_are_atomic(): void
    {
        foreach (['verification', 'entitlement', 'consent', 'rule'] as $change) {
            [$user, $org, $id] = $this->schedule();
            app(BusinessSchedules::class)->materialize($user->id, $org, $id);
            if ($change === 'verification') {
                $this->control->table('users')->where('id', $user->id)->update(['email_verified_at' => null]);
            } elseif ($change === 'entitlement') {
                $plan = (string) Str::uuid();
                $this->control->table('pilot_plan_versions')->insert(['id' => $plan, 'name' => $plan, 'version' => 1, 'quotas' => '{}']);
                $this->control->table('pilot_assignments')->insert(['id' => (string) Str::uuid(), 'org_id' => $org, 'plan_id' => $plan, 'version' => 1, 'starts_at' => now()->subDays(2), 'ends_at' => now()->subDay()]);
                $this->patchJson('/automation/schedules/'.$id, $this->weekly(['version' => 1, 'name' => 'Rejected change']))->assertUnprocessable();
                $this->assertSame('Weekly review', $this->control->table('business_schedules')->where('id', $id)->value('name'));
                $this->assertSame('ready', $this->occurrence($id)->state);
                $this->assertSame(1, $this->control->table('automation_consent_events')->where('schedule_id', $id)->count());
            } elseif ($change === 'consent') {
                $this->putJson('/automation/preferences', $this->preferences(['version' => 2, 'weekly_digest' => false]))->assertOk();
            } else {
                $this->patchJson('/automation/schedules/'.$id, $this->weekly(['version' => 1, 'local_time' => '11:00']))->assertOk();
            }
            app(BusinessSchedules::class)->execute($user->id, $org, $id, $this->occurrence($id)->id, fn () => $this->fail('Obsolete run executed: '.$change));
            DB::select('SELECT app_reconcile_schedules()');
            $this->assertSame('cancelled', $this->occurrence($id)->state, $change);
        }
    }

    public function test_follow_up_subject_changes_and_long_downtime_do_not_execute_stale_work_or_burst(): void
    {
        [$user, $org, $id] = $this->schedule();
        $this->control->table('business_schedules')->where('id', $id)->update(['next_due_at' => now()->subWeeks(20)]);
        app(BusinessSchedules::class)->materialize($user->id, $org, $id);
        app(BusinessSchedules::class)->materialize($user->id, $org, $id);
        $this->assertSame(1, $this->control->table('schedule_runs')->where('schedule_id', $id)->count());
        $this->assertTrue(CarbonImmutable::parse($this->control->table('business_schedules')->where('id', $id)->value('next_due_at'))->isFuture());
        $lead = $this->postJson('/pipeline', ['brand_name' => 'Draft subject', 'currency' => 'PHP', 'value_minor' => '0'])->assertCreated()->json('id');
        $draft = $this->postJson('/automation/schedules', ['name' => 'Prepare follow-up', 'kind' => 'follow_up_draft', 'subject_id' => $lead,
            'due_at' => now()->addHour()->format('Y-m-d\TH:i:sP'), 'consent' => true])->assertCreated()->json('id');
        $this->control->table('business_schedules')->where('id', $draft)->update(['next_due_at' => now()->subMinute()]);
        app(BusinessSchedules::class)->materialize($user->id, $org, $draft);
        $this->patchJson('/pipeline/'.$lead, ['version' => 1, 'brand_name' => 'Changed', 'currency' => 'PHP', 'value_minor' => '0'])->assertOk();
        app(BusinessSchedules::class)->execute($user->id, $org, $draft, $this->occurrence($draft)->id, fn () => $this->fail('Stale draft executed'));
        $this->assertSame('cancelled', $this->occurrence($draft)->state);
    }
}
