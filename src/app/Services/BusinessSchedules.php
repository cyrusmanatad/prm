<?php

namespace App\Services;

use App\Jobs\MaterializeSchedule;
use App\Models\User;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** HTTP mutations run inside WorkspaceContext. Jobs own a fresh tenant transaction. */
class BusinessSchedules
{
    public const PREFERENCES = ['reminder' => 'reminders', 'weekly_digest' => 'weekly_digest', 'follow_up_draft' => 'follow_up_drafts'];

    public function preferences(int $actor, string $org): object
    {
        return DB::table('automation_preferences')->where(['org_id' => $org, 'actor_id' => $actor])->first()
            ?? (object) ['timezone' => 'UTC', 'reminders' => false, 'weekly_digest' => false, 'follow_up_drafts' => false, 'version' => 0];
    }

    private function lockPreferences(int $actor, string $org): object
    {
        DB::table('automation_preferences')->insertOrIgnore(['org_id' => $org, 'actor_id' => $actor]);

        return DB::table('automation_preferences')->where(['org_id' => $org, 'actor_id' => $actor])->lockForUpdate()->first();
    }

    public function savePreferences(int $actor, string $org, array $data): void
    {
        DB::transaction(function () use ($actor, $org, $data): void {
            $this->writer($actor, $org);
            $existing = $this->preferences($actor, $org);
            $p = $this->lockPreferences($actor, $org);
            $this->version($existing->version === 0 && $p->version === 1 ? 0 : $p->version, $data['version']);
            $version = $p->version + 1;
            DB::table('automation_preferences')->where(['org_id' => $org, 'actor_id' => $actor])->update([
                ...$data, 'version' => $version, 'updated_at' => now(),
            ]);
            foreach (self::PREFERENCES as $kind => $field) {
                $this->consent($actor, $org, null, $version, $kind, $data[$field]);
            }
            foreach (DB::table('business_schedules')->where(['org_id' => $org, 'actor_id' => $actor, 'enabled' => true])->lockForUpdate()->get() as $s) {
                $enabled = $data[self::PREFERENCES[$s->kind]];
                $due = $s->kind === 'weekly_digest'
                    ? app(ScheduleClock::class)->nextWeekly($data['timezone'], $s->weekday, $s->local_time, CarbonImmutable::now()) : $s->next_due_at;
                DB::table('business_schedules')->where('id', $s->id)->update([
                    'timezone' => $data['timezone'], 'preference_version' => $version, 'version' => $s->version + 1,
                    'enabled' => $enabled, 'revoked_at' => $enabled ? null : now(), 'next_due_at' => $due, 'updated_at' => now(),
                ]);
                $this->cancelRuns($s->id);
            }
            app(Audit::class)->record($actor, $org, 'automation.preferences.saved', $org);
        });
    }

    public function save(int $actor, string $org, array $data, ?string $id = null): string
    {
        return DB::transaction(function () use ($actor, $org, $data, $id): string {
            $this->writer($actor, $org);
            $this->entitlementLock($org);
            $p = $this->lockPreferences($actor, $org);
            if (! $p->{self::PREFERENCES[$data['kind']]}) {
                throw ValidationException::withMessages(['consent' => 'Enable this notice type in your preferences first.']);
            }
            $old = $id ? DB::table('business_schedules')->where('id', $id)->lockForUpdate()->first() : null;
            abort_if($id && ! $old, 404);
            if ($old) {
                $this->version($old->version, $data['version']);
            }
            $subject = null;
            if ($data['kind'] !== 'weekly_digest') {
                $table = $data['kind'] === 'reminder' ? 'reminders' : 'pipeline_entries';
                $subject = DB::table($table)->where('org_id', $org)->where('id', $data['subject_id'])->lockForUpdate()->first();
                abort_unless($subject, 404);
                if (($data['kind'] === 'reminder' && $subject->completed_at) || ($data['kind'] === 'follow_up_draft' && $subject->stage === 'lost')) {
                    throw ValidationException::withMessages(['subject_id' => 'Choose an active record.']);
                }
            }
            if (DB::table('business_schedules')->where('kind', 'weekly_digest')->where('enabled', true)->when($id, fn ($q) => $q->where('id', '<>', $id))->exists() && $data['kind'] === 'weekly_digest') {
                throw ValidationException::withMessages(['kind' => 'You already have an active weekly digest schedule.']);
            }
            $id ??= (string) Str::uuid();
            $row = ['name' => $data['name'], 'kind' => $data['kind'], 'subject_id' => $subject?->id, 'subject_version' => $subject?->version,
                'rule_version' => 1, 'version' => $old ? $old->version + 1 : 1, 'preference_version' => $p->version,
                'timezone' => $p->timezone, 'weekday' => $data['kind'] === 'weekly_digest' ? $data['weekday'] : null,
                'local_time' => $data['kind'] === 'weekly_digest' ? $data['local_time'] : null,
                'next_due_at' => $data['kind'] === 'weekly_digest'
                    ? app(ScheduleClock::class)->nextWeekly($p->timezone, $data['weekday'], $data['local_time'], CarbonImmutable::now())
                    : CarbonImmutable::parse($data['due_at'])->utc(),
                'enabled' => true, 'consented_at' => now(), 'revoked_at' => null, 'updated_at' => now()];
            if ($old) {
                DB::table('business_schedules')->where('id', $id)->update($row);
                $this->cancelRuns($id);
            } else {
                DB::table('business_schedules')->insert([...$row, 'id' => $id, 'org_id' => $org, 'actor_id' => $actor]);
            }
            if (! DB::selectOne('SELECT app_schedule_current(?::uuid) AS ok', [$id])->ok) {
                throw ValidationException::withMessages(['schedule' => 'Current access or pilot entitlement does not permit scheduling.']);
            }
            $this->consent($actor, $org, $id, $row['version'], $data['kind'], true);
            app(Audit::class)->record($actor, $org, 'automation.schedule.saved', $id);

            return $id;
        });
    }

    public function revoke(int $actor, string $org, string $id, int $version): void
    {
        DB::transaction(function () use ($actor, $org, $id, $version): void {
            $this->writer($actor, $org);
            $this->lockPreferences($actor, $org);
            $s = DB::table('business_schedules')->where('id', $id)->lockForUpdate()->first();
            abort_unless($s, 404);
            $this->version($s->version, $version);
            DB::table('business_schedules')->where('id', $id)->update(['enabled' => false, 'revoked_at' => now(), 'version' => $version + 1, 'updated_at' => now()]);
            $this->cancelRuns($id);
            $this->consent($actor, $org, $id, $version + 1, $s->kind, false);
            app(Audit::class)->record($actor, $org, 'automation.schedule.revoked', $id);
        });
    }

    public function dispatch(): int
    {
        DB::select('SELECT app_reconcile_schedules()');
        $rows = DB::select('SELECT * FROM app_due_schedules()');
        foreach ($rows as $s) {
            MaterializeSchedule::dispatch($s->actor_id, $s->org_id, $s->id);
        }

        return count($rows);
    }

    public function materialize(int $actor, string $org, string $id): void
    {
        $this->inSchedule($actor, $org, $id, function (object $s): void {
            if (! $s->next_due_at || CarbonImmutable::parse($s->next_due_at)->isFuture()) {
                return;
            }
            DB::table('schedule_runs')->insertOrIgnore([
                'id' => (string) Str::uuid(), 'org_id' => $s->org_id, 'actor_id' => $s->actor_id, 'schedule_id' => $s->id,
                'schedule_version' => $s->version, 'preference_version' => $s->preference_version, 'rule_version' => $s->rule_version,
                'due_at' => $s->next_due_at,
            ]);
            // Coalesce missed weeks into one occurrence; never burst a backlog after downtime.
            DB::table('business_schedules')->where('id', $s->id)->update(['next_due_at' => $s->kind === 'weekly_digest'
                ? app(ScheduleClock::class)->nextWeekly($s->timezone, $s->weekday, $s->local_time, CarbonImmutable::now()) : null]);
        });
    }

    /** M5-04+ consumers append durable intent in this transaction, never call external services. */
    public function execute(int $actor, string $org, string $schedule, string $run, Closure $appendIntent): void
    {
        $this->inSchedule($actor, $org, $schedule, function (object $s) use ($run, $appendIntent): void {
            $r = DB::table('schedule_runs')->where('schedule_id', $s->id)->where('id', $run)->lockForUpdate()->first();
            if (! $r || $r->state !== 'ready') {
                return;
            }
            $superseded = $s->kind === 'weekly_digest' && DB::table('schedule_runs')->where('schedule_id', $s->id)
                ->where('schedule_version', $s->version)->where('due_at', '>', $r->due_at)->where('state', '<>', 'cancelled')->exists();
            if ($superseded || $r->schedule_version !== $s->version || $r->preference_version !== $s->preference_version || $r->rule_version !== $s->rule_version) {
                DB::table('schedule_runs')->where('id', $run)->update(['state' => 'cancelled', 'completed_at' => now()]);

                return;
            }
            $appendIntent($s, $r);
            DB::table('schedule_runs')->where('id', $run)->update(['state' => 'completed', 'completed_at' => now()]);
        });
    }

    private function inSchedule(int $actor, string $org, string $id, Closure $callback): void
    {
        app(TenantContext::class)->run($actor, $org, function () use ($actor, $org, $id, $callback): void {
            if (! DB::selectOne('SELECT app_lock_membership(?::uuid) AS allowed', [$org])->allowed) {
                return;
            }
            $s = $this->lockCurrent($actor, $org, $id);
            if (! $s) {
                return;
            }
            $callback($s);
        }, requireMembership: false);
    }

    /** Caller owns a tenant transaction and the current membership lock. Shared by consumption and send reservation. */
    public function lockCurrent(int $actor, string $org, string $id): ?object
    {
        $this->entitlementLock($org);
        DB::table('automation_preferences')->where(['org_id' => $org, 'actor_id' => $actor])->lockForUpdate()->first();
        $s = DB::table('business_schedules')->where('id', $id)->lockForUpdate()->first();
        if (! $s) {
            return null;
        }
        if ($s->kind !== 'weekly_digest') {
            DB::table($s->kind === 'reminder' ? 'reminders' : 'pipeline_entries')->where('id', $s->subject_id)->lockForUpdate()->first();
        }
        if (! DB::selectOne('SELECT app_schedule_current(?::uuid) AS ok', [$id])->ok) {
            $this->cancelRuns($id);

            return null;
        }

        return $s;
    }

    private function cancelRuns(string $id): void
    {
        DB::table('schedule_runs')->where('schedule_id', $id)->where('state', 'ready')->update(['state' => 'cancelled', 'completed_at' => now()]);
    }

    private function entitlementLock(string $org): void
    {
        DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ['pilot:'.$org]);
    }

    private function writer(int $actor, string $org): void
    {
        abort_unless(app(Pipeline::class)->canWrite($actor, $org) && User::find($actor)?->hasVerifiedEmail(), 403);
    }

    private function version(int $actual, int $expected): void
    {
        if ($actual !== $expected) {
            throw ValidationException::withMessages(['version' => 'This record changed. Reload before saving again.'])->status(409);
        }
    }

    private function consent(int $actor, string $org, ?string $schedule, int $version, string $kind, bool $granted): void
    {
        DB::table('automation_consent_events')->insert(['id' => (string) Str::uuid(), 'org_id' => $org, 'actor_id' => $actor,
            'schedule_id' => $schedule, 'version' => $version, 'kind' => $kind, 'granted' => $granted]);
    }
}
