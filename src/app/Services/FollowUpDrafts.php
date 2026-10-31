<?php

namespace App\Services;

use App\Jobs\PrepareFollowUp;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Private preparation and manual handoff only. This service never sends email. */
class FollowUpDrafts
{
    public function dispatch(): int
    {
        DB::select('SELECT app_reconcile_schedules()');
        $rows = DB::select('SELECT * FROM app_ready_follow_up_drafts()');
        foreach ($rows as $r) {
            PrepareFollowUp::dispatch($r->actor_id, $r->org_id, $r->schedule_id, $r->id);
        }

        return count($rows);
    }

    public function consume(int $actor, string $org, string $schedule, string $run): void
    {
        app(BusinessSchedules::class)->execute($actor, $org, $schedule, $run, function (object $s, object $r) use ($actor, $org): void {
            abort_unless($s->kind === 'follow_up_draft', 422);
            $this->insert($actor, $org, $s->subject_id, $s->subject_version, [
                'recipient' => '', 'subject' => 'Following up',
                'body' => 'Hello, I wanted to follow up on our collaboration. Would you like to discuss next steps?',
            ], $r->id);
        });
    }

    public function save(int $actor, string $org, array $data, ?string $id = null): string
    {
        $this->writer($actor, $org);
        $old = $id ? DB::table('follow_up_drafts')->where('id', $id)->first() : null;
        abort_if($id && ! $old, 404);
        if ($old) {
            $this->version($old, $data['version']);
            abort_if(in_array($old->state, ['discarded', 'cancelled'], true), 422, 'Discarded or cancelled drafts cannot be edited.');
        }
        $entry = DB::table('pipeline_entries')->where('id', $old?->pipeline_entry_id ?? $data['pipeline_entry_id'])->lockForUpdate()->first();
        if ($old) {
            $old = $this->lock($id);
            $this->version($old, $data['version']);
            abort_if(in_array($old->state, ['discarded', 'cancelled'], true), 422, 'Discarded or cancelled drafts cannot be edited.');
        }
        abort_unless($entry, 404);
        abort_if($entry->stage === 'lost', 422, 'Choose an active lead.');
        $content = array_intersect_key($data, array_flip(['recipient', 'subject', 'body']));
        if (! $old) {
            return $this->insert($actor, $org, $entry->id, $entry->version, $content);
        }
        DB::table('follow_up_drafts')->where('id', $id)->update([...$content, 'subject_version' => $entry->version,
            'version' => $old->version + 1, 'state' => 'draft', 'approved_version' => null, 'approved_at' => null, 'updated_at' => now()]);
        app(Audit::class)->record($actor, $org, 'follow_up.saved', $id);

        return $id;
    }

    public function transition(int $actor, string $org, string $id, int $version, string $action): ?string
    {
        $this->writer($actor, $org);
        // Same schedule-before-draft ordering as consumption, including consent and subject locks.
        $routing = DB::table('follow_up_drafts')->where('id', $id)->first();
        abort_unless($routing, 404);
        $schedule = null;
        if ($routing->schedule_run_id) {
            $run = DB::table('schedule_runs')->where('id', $routing->schedule_run_id)->first();
            $schedule = app(BusinessSchedules::class)->lockCurrent($actor, $org, $run->schedule_id);
        }
        $entry = DB::table('pipeline_entries')->where('id', $routing->pipeline_entry_id)->lockForUpdate()->first();
        $d = $this->lock($id);
        $this->version($d, $version);
        abort_if(in_array($d->state, ['discarded', 'cancelled'], true), 422, 'This draft was discarded or cancelled after a reply.');
        if (in_array($action, ['approve', 'handoff'], true)) {
            if (! $entry || $entry->stage === 'lost' || $entry->version !== $d->subject_version || ($d->schedule_run_id && (! $schedule || $schedule->kind !== 'follow_up_draft' || $schedule->subject_id !== $d->pipeline_entry_id || $run->rule_version !== $schedule->rule_version || $run->schedule_version !== $schedule->version || $run->preference_version !== $schedule->preference_version || $run->state !== 'completed'))) {
                throw ValidationException::withMessages(['version' => 'The lead or schedule changed. Save a fresh manual draft before approval.'])->status(409);
            }
            validator((array) $d, ['recipient' => ['required', 'email:rfc', 'max:255', 'not_regex:/[\r\n]/'],
                'subject' => ['required', 'string', 'max:240', 'not_regex:/[\r\n]/'], 'body' => ['required', 'string', 'max:10000']])->validate();
        }
        if ($action === 'handoff') {
            abort_unless($d->state === 'approved' && $d->approved_version === $d->version, 409, 'Approve the current draft first.');

            return "To: {$d->recipient}\nSubject: {$d->subject}\n\n{$d->body}\n";
        }
        $next = $d->version + 1;
        DB::table('follow_up_drafts')->where('id', $id)->update(['state' => $action === 'approve' ? 'approved' : 'discarded',
            'version' => $next, 'approved_version' => $action === 'approve' ? $next : null,
            'approved_at' => $action === 'approve' ? now() : null, 'updated_at' => now()]);
        app(Audit::class)->record($actor, $org, 'follow_up.'.$action, $id);

        return null;
    }

    private function insert(int $actor, string $org, string $entry, int $version, array $content, ?string $run = null): string
    {
        $id = (string) Str::uuid();
        DB::table('follow_up_drafts')->insert([...$content, 'id' => $id, 'org_id' => $org, 'actor_id' => $actor,
            'pipeline_entry_id' => $entry, 'subject_version' => $version, 'schedule_run_id' => $run]);
        app(Audit::class)->record($actor, $org, 'follow_up.created', $id);

        return $id;
    }

    private function lock(string $id): object
    {
        $draft = DB::table('follow_up_drafts')->where('id', $id)->lockForUpdate()->first();
        abort_unless($draft, 404);

        return $draft;
    }

    private function writer(int $actor, string $org): void
    {
        abort_unless(app(Pipeline::class)->canWrite($actor, $org) && User::find($actor)?->hasVerifiedEmail(), 403);
        DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ['pilot:'.$org]);
        abort_if(in_array(app(PilotEntitlements::class)->state($org)['state'], ['expired', 'scheduled'], true), 403);
    }

    private function version(object $draft, int $expected): void
    {
        if ($draft->version !== $expected) {
            throw ValidationException::withMessages(['version' => 'This draft changed. Reload before trying again.'])->status(409);
        }
    }
}
