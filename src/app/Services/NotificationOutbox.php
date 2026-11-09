<?php

namespace App\Services;

use App\Jobs\DeliverNotification;
use App\Mail\FollowUpNotice;
use App\Mail\OnboardingInvitation;
use App\Mail\OnboardingLifecycle;
use App\Mail\PartnerInvitation;
use App\Mail\ScheduledNotice;
use App\Models\User;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

class NotificationOutbox
{
    /** Called inside the invitation's tenant transaction, after explicit send consent. */
    public function invitation(int $actor, string $org, string $invitation, string $url): string
    {
        $id = (string) Str::uuid();
        DB::table('notification_outbox')->insert([
            'id' => $id, 'org_id' => $org, 'actor_id' => $actor,
            'kind' => 'partner.invitation', 'subject_id' => $invitation,
            'encrypted_payload' => encrypt($url),
        ]);
        app(Audit::class)->record($actor, $org, 'notification.requested', $id);

        return $id;
    }

    public function dispatch(): int
    {
        DB::select('SELECT app_reconcile_notifications()');
        $rows = DB::select('SELECT * FROM app_claim_notifications()');
        $queued = 0;
        foreach ($rows as $row) {
            try {
                DeliverNotification::dispatch($row->actor_id, $row->org_id, $row->id);
                $queued++;
            } catch (Throwable) {
                // Do not persist payload-bearing exception text or lose other workspaces' turns.
                app(TenantContext::class)->run($row->actor_id, $row->org_id, function () use ($row): void {
                    if (! DB::selectOne('SELECT app_lock_membership(?::uuid) AS allowed', [$row->org_id])->allowed || ! app(ProfessionalAccess::class)->active($row->org_id)) {
                        return;
                    }
                    $pending = DB::table('notification_outbox')->where('id', $row->id)->where('state', 'pending')->lockForUpdate()->first();
                    if ($pending) {
                        $this->defer($pending, 'queue_unavailable');
                    }
                }, requireMembership: false);
            }
        }

        return $queued;
    }

    /** The caller holds the onboarding request lock in the event transaction. */
    public function onboardingInvitation(int $actor, string $org, string $request, string $token, string $url, string $recipient): string
    {
        $id = (string) Str::uuid();
        DB::table('notification_outbox')->insert([
            'id' => $id, 'org_id' => $org, 'actor_id' => $actor,
            'kind' => 'onboarding.invitation', 'onboarding_request_id' => $request,
            'invitation_hash' => hash('sha256', $token),
            'encrypted_payload' => encrypt(['url' => $url, 'recipient' => $recipient]),
        ]);
        app(Audit::class)->record($actor, $org, 'notification.requested', $id);

        return $id;
    }

    /** The caller holds the request lock in the review transaction. */
    public function onboardingOutcome(int $actor, string $org, string $request, int $version, string $action, string $generation, string $expires, string $recipient): string
    {
        $id = (string) Str::uuid();
        $token = bin2hex(random_bytes(32));
        $kind = $action === 'correct' ? 'onboarding.corrected' : 'onboarding.accepted';
        DB::table('notification_outbox')->insert([
            'id' => $id, 'org_id' => $org, 'actor_id' => $actor, 'kind' => $kind,
            'onboarding_request_id' => $request, 'onboarding_version' => $version, 'invitation_hash' => $generation,
            'encrypted_payload' => encrypt(['recipient' => $recipient, 'url' => rtrim(config('app.url'), '/').'/onboarding#token='.$token]),
        ]);
        DB::table('onboarding_return_links')->insert([
            'id' => (string) Str::uuid(), 'org_id' => $org, 'request_id' => $request, 'notification_id' => $id,
            'token_hash' => hash('sha256', $token), 'expires_at' => $expires,
        ]);
        app(Audit::class)->record($actor, $org, 'notification.requested', $id);

        return $id;
    }

    public function deliver(int $actor, string $org, string $id): void
    {
        $context = app(TenantContext::class);
        // Commit the send reservation before contacting SMTP. Queue retries cannot resend it.
        $message = $context->run($actor, $org, function () use ($actor, $org, $id): ?array {
            if (! DB::selectOne('SELECT app_lock_membership(?::uuid) AS allowed', [$org])->allowed) {
                return null;
            }
            if (! app(ProfessionalAccess::class)->active($org)) {
                DB::table('notification_outbox')->where('id', $id)->where('state', 'pending')->update(['state' => 'cancelled', 'encrypted_payload' => null, 'completed_at' => now()]);

                return null;
            }
            // Discover routing only, then use the same schedule-before-outbox lock order as the consumer.
            $route = DB::table('notification_outbox')->where('id', $id)->where('actor_id', $actor)->first();
            $schedule = null;
            $run = null;
            $draft = null;
            if ($route?->follow_up_draft_id) {
                $draft = DB::table('follow_up_drafts')->where('id', $route->follow_up_draft_id)->first();
                if ($draft?->schedule_run_id) {
                    $draftRun = DB::table('schedule_runs')->where('id', $draft->schedule_run_id)->first();
                    if ($draftRun) {
                        app(BusinessSchedules::class)->lockCurrent($actor, $org, $draftRun->schedule_id);
                    }
                }
                if ($draft) {
                    DB::table('pipeline_entries')->where('id', $draft->pipeline_entry_id)->lockForUpdate()->first();
                    $draft = DB::table('follow_up_drafts')->where('id', $draft->id)->lockForUpdate()->first();
                }
            }
            if ($route?->schedule_run_id) {
                $run = DB::table('schedule_runs')->where('id', $route->schedule_run_id)->first();
                $schedule = $run ? app(BusinessSchedules::class)->lockCurrent($actor, $org, $run->schedule_id) : null;
            }
            $row = DB::table('notification_outbox')->where('id', $id)->where('org_id', $org)->whereRaw('available_at<=clock_timestamp()')->lockForUpdate()->first();
            if (! $row || $row->actor_id !== $actor || $row->state !== 'pending') {
                return null;
            }
            if ($row->follow_up_draft_id) {
                if (! $draft || ! DB::selectOne('SELECT app_follow_up_notice_current(?::uuid) AS ok', [$id])->ok) {
                    $this->finish($id, 'cancelled');

                    return null;
                }

                return $this->reserveFollowUp($row, $draft);
            }
            if ($row->schedule_run_id) {
                if (! $schedule || ! DB::selectOne('SELECT app_schedule_notice_current(?::uuid) AS ok', [$id])->ok) {
                    $this->finish($id, 'cancelled');

                    return null;
                }

                return $this->reserveScheduled($row, $schedule, $run);
            }
            $onboarding = str_starts_with($row->kind, 'onboarding.');
            $lifecycle = $onboarding && $row->kind !== 'onboarding.invitation';
            $invite = $onboarding
                ? DB::table('onboarding_requests')->where('org_id', $org)->where('id', $row->onboarding_request_id)->lockForUpdate()->first()
                : DB::selectOne('SELECT * FROM app_lock_notification_invitation(?::uuid)', [$row->subject_id]);
            $valid = $invite && now()->lt($invite->expires_at) && ($onboarding
                ? $invite->status !== 'revoked' && hash_equals($invite->token_hash, $row->invitation_hash)
                    && ($lifecycle ? $this->currentLifecycle($row, $invite) : $invite->consumed_at === null)
                : $invite->status === 'pending');
            if (! User::find($actor)?->hasVerifiedEmail() || ! $valid) {
                $this->finish($id, 'cancelled');

                return null;
            }
            // Never write token-bearing messages to logs or silently fail over to logs.
            $mailer = config('mail.default');
            if (! in_array($mailer, ['smtp', 'array'], true) || ($mailer === 'array' && ! app()->environment(['local', 'testing']))) {
                $this->defer($row, 'transport_unavailable');

                return null;
            }
            if (! $this->budget($row)) {
                return null;
            }
            $payload = $row->kind === 'onboarding.submitted'
                ? ['recipient' => User::findOrFail($actor)->email, 'url' => rtrim(config('app.url'), '/').'/partner/onboarding?request='.$row->onboarding_request_id]
                : decrypt($row->encrypted_payload);
            DB::table('notification_outbox')->where('id', $id)->update(['state' => 'sending', 'started_at' => now(), 'failure_code' => null]);

            return [$onboarding ? $payload['recipient'] : $invite->recipient_email,
                $lifecycle ? new OnboardingLifecycle($row->kind, $payload['url'], $invite->expires_at, $id)
                    : ($onboarding ? new OnboardingInvitation($payload['url'], $invite->expires_at, $id) : new PartnerInvitation($payload, $invite->expires_at, $id)), $mailer];
        }, requireMembership: false);
        if (! $message) {
            return;
        }

        [$recipient, $mail, $mailer] = $message;
        try {
            Mail::mailer($mailer)->to($recipient)->send($mail);
            $state = $mailer === 'array' ? 'captured' : 'sent';
        } catch (Throwable) {
            // Exception text can contain addresses, credentials or capability URLs.
            $state = 'uncertain';
        }
        // Sending already began under the authorization above. A later revocation must
        // not convert a completed/ambiguous delivery into a retryable pending intent.
        $context->run($actor, $org, function () use ($id, $state): void {
            DB::table('notification_outbox')->where('id', $id)->where('state', 'sending')->update([
                'state' => $state, 'encrypted_payload' => null, 'completed_at' => now(),
            ]);
        }, requireMembership: false);
    }

    private function reserveScheduled(object $row, object $schedule, object $run): ?array
    {
        $mailer = config('mail.default');
        if (! in_array($mailer, ['smtp', 'array'], true) || ($mailer === 'array' && ! app()->environment(['local', 'testing']))) {
            $this->defer($row, 'transport_unavailable');

            return null;
        }
        $user = User::findOrFail($row->actor_id);
        if (! $user->hasVerifiedEmail()) {
            $this->finish($row->id, 'cancelled');

            return null;
        }
        if (! $this->budget($row)) {
            return null;
        }
        $summary = $row->kind === 'schedule.weekly_digest' ? [
            'dueReminders' => DB::table('reminders')->whereNull('completed_at')->where('due_at', '<=', now())->count(),
            'onboardingReviews' => DB::table('onboarding_requests')->where('status', 'submitted')->where('expires_at', '>', now())->count(),
        ] : [];
        DB::table('notification_outbox')->where('id', $row->id)->update(['state' => 'sending', 'started_at' => now(), 'failure_code' => null]);

        return [$user->email, new ScheduledNotice($row->kind, $schedule->timezone,
            CarbonImmutable::parse($run->due_at)->setTimezone($schedule->timezone)->format('Y-m-d H:i P'), $row->org_id, $row->id, $summary), $mailer];
    }

    private function reserveFollowUp(object $row, object $draft): ?array
    {
        $mailer = config('mail.default');
        if (! in_array($mailer, ['smtp', 'array'], true) || ($mailer === 'array' && ! app()->environment(['local', 'testing']))) {
            $this->defer($row, 'transport_unavailable');

            return null;
        }
        if (! $this->budget($row)) {
            return null;
        }
        $review = $row->kind === 'follow_up.review';
        $recipient = $review ? User::findOrFail($row->actor_id)->email : $draft->recipient;
        validator(['recipient' => $recipient, 'subject' => $draft->subject, 'body' => $draft->body], [
            'recipient' => ['required', 'email:rfc', 'max:255', 'not_regex:/[\r\n]/'],
            'subject' => ['required', 'string', 'max:240', 'not_regex:/[\r\n]/'], 'body' => ['required', 'string', 'max:10000'],
        ])->validate();
        DB::table('notification_outbox')->where('id', $row->id)->update(['state' => 'sending', 'started_at' => now(), 'failure_code' => null]);

        return [$recipient, new FollowUpNotice($review, $row->id, $review ? '' : $draft->subject, $review ? '' : $draft->body, User::findOrFail($row->actor_id)->email), $mailer];
    }

    private function budget(object $row): bool
    {
        $options = ['options' => ['min_range' => 0, 'max_range' => 2147483646]];
        $workspace = filter_var(config('notifications.workspace_per_minute'), FILTER_VALIDATE_INT, $options);
        $global = filter_var(config('notifications.global_per_minute'), FILTER_VALIDATE_INT, $options);
        // Invalid configuration must not silently disable a configured provider safeguard.
        if ($workspace === false || $global === false) {
            $this->defer($row, $workspace === false ? 'workspace_budget' : 'global_budget');

            return false;
        }
        $reason = DB::selectOne('SELECT app_notification_budget(?,?) AS reason', [$workspace, $global])->reason;
        if ($reason !== null) {
            $this->defer($row, $reason);

            return false;
        }

        return true;
    }

    private function defer(object $row, string $reason): void
    {
        $seconds = in_array($reason, ['workspace_budget', 'global_budget'], true)
            ? 60 : min(3600, 60 * (2 ** min($row->defer_count, 6)));
        $available = DB::selectOne('SELECT clock_timestamp()+make_interval(secs=>?::integer) AS due', [$seconds])->due;
        DB::table('notification_outbox')->where('id', $row->id)->where('state', 'pending')->update([
            'available_at' => $available, 'defer_count' => $row->defer_count + 1, 'failure_code' => $reason,
        ]);
    }

    private function finish(string $id, string $state): void
    {
        DB::table('notification_outbox')->where('id', $id)->update([
            'state' => $state, 'encrypted_payload' => null, 'completed_at' => now(),
        ]);
    }

    private function currentLifecycle(object $row, object $request): bool
    {
        if ($row->onboarding_version !== $request->version) {
            return false;
        }
        if ($row->kind === 'onboarding.submitted') {
            return $request->status === 'submitted' && $request->submission_notify_user_id === $row->actor_id;
        }
        $status = $row->kind === 'onboarding.corrected' ? 'correction' : 'accepted';

        return $request->status === $status && DB::table('onboarding_return_links')
            ->where('notification_id', $row->id)->whereNull('consumed_at')->where('expires_at', '>', now())->exists();
    }
}
