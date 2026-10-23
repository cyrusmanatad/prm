<?php

namespace App\Services;

use App\Jobs\DeliverNotification;
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
            $invite = DB::selectOne('SELECT * FROM app_lock_notification_invitation(?::uuid)', [$row->subject_id]);
            if (! User::find($actor)?->hasVerifiedEmail() || ! $invite || $invite->status !== 'pending' || now()->gte($invite->expires_at)) {
                $this->finish($id, 'cancelled');

                return null;
            }
            // Never write token-bearing messages to logs or silently fail over to logs.
            $mailer = config('mail.default');
            if (! in_array($mailer, ['smtp', 'array'], true) || ($mailer === 'array' && ! app()->environment(['local', 'testing']))) {
                return null;
            }
            $url = decrypt($row->encrypted_payload);
            DB::table('notification_outbox')->where('id', $id)->update(['state' => 'sending', 'started_at' => now()]);

            return [$invite->recipient_email, $url, $invite->expires_at, $mailer];
        }, requireMembership: false);
        if (! $message) {
            return;
        }

        [$recipient, $url, $expiry, $mailer] = $message;
        try {
            Mail::mailer($mailer)->to($recipient)->send(new PartnerInvitation($url, $expiry, $id));
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
