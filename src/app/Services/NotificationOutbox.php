<?php

namespace App\Services;

use App\Jobs\DeliverNotification;
use App\Mail\OnboardingInvitation;
use App\Mail\PartnerInvitation;
use App\Models\User;
use App\Tenancy\TenantContext;
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
        $rows = DB::select('SELECT * FROM app_pending_notifications()');
        foreach ($rows as $row) {
            DeliverNotification::dispatch($row->actor_id, $row->org_id, $row->id);
        }

        return count($rows);
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

    public function deliver(int $actor, string $org, string $id): void
    {
        $context = app(TenantContext::class);
        // Commit the send reservation before contacting SMTP. Queue retries cannot resend it.
        $message = $context->run($actor, $org, function () use ($actor, $org, $id): ?array {
            if (! DB::selectOne('SELECT app_lock_membership(?::uuid) AS allowed', [$org])->allowed) {
                return null;
            }
            $row = DB::table('notification_outbox')->where('id', $id)->where('org_id', $org)->lockForUpdate()->first();
            if (! $row || $row->actor_id !== $actor || $row->state !== 'pending') {
                return null;
            }
            $onboarding = $row->kind === 'onboarding.invitation';
            $invite = $onboarding
                ? DB::table('onboarding_requests')->where('org_id', $org)->where('id', $row->onboarding_request_id)->lockForUpdate()->first()
                : DB::selectOne('SELECT * FROM app_lock_notification_invitation(?::uuid)', [$row->subject_id]);
            $valid = $invite && now()->lt($invite->expires_at) && ($onboarding
                ? $invite->status !== 'revoked' && $invite->consumed_at === null && hash_equals($invite->token_hash, $row->invitation_hash)
                : $invite->status === 'pending');
            if (! User::find($actor)?->hasVerifiedEmail() || ! $valid) {
                $this->finish($id, 'cancelled');

                return null;
            }
            // Never write token-bearing messages to logs or silently fail over to logs.
            $mailer = config('mail.default');
            if (! in_array($mailer, ['smtp', 'array'], true) || ($mailer === 'array' && ! app()->environment(['local', 'testing']))) {
                return null;
            }
            $payload = decrypt($row->encrypted_payload);
            DB::table('notification_outbox')->where('id', $id)->update(['state' => 'sending', 'started_at' => now()]);

            return [$onboarding ? $payload['recipient'] : $invite->recipient_email,
                $onboarding ? new OnboardingInvitation($payload['url'], $invite->expires_at, $id) : new PartnerInvitation($payload, $invite->expires_at, $id), $mailer];
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

    private function finish(string $id, string $state): void
    {
        DB::table('notification_outbox')->where('id', $id)->update([
            'state' => $state, 'encrypted_payload' => null, 'completed_at' => now(),
        ]);
    }
}
