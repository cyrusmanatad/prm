<?php

namespace App\Services;

use App\Jobs\DeliverAccountNotification;
use App\Mail\BrandRequestOutcome;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Account-scoped transactional notices: Brand request outcomes (D37). Intent is written with the decision in
 * PostgreSQL; this class only moves committed intent to the mail transport. A failure here never changes
 * the decision or the status shown in the application.
 */
class AccountNotifications
{
    /** Scheduler pass: reconcile, then queue due notices. Redis is disposable; pending intent stays eligible. */
    public function dispatch(): int
    {
        $queued = 0;
        foreach (DB::select('SELECT notification_id FROM app_account_notifications_claim()') as $row) {
            try {
                DeliverAccountNotification::dispatch($row->notification_id);
                $queued++;
            } catch (Throwable) {
                DB::select('SELECT app_account_notification_defer(?::uuid,?)', [$row->notification_id, 'queue_unavailable']);
            }
        }

        return $queued;
    }

    public function deliver(string $id): void
    {
        if (DB::connection()->transactionLevel() !== 0) {
            throw new \LogicException('Account mail reservation must commit before transport.');
        }
        // Never write to logs or silently fail over to them.
        $mailer = config('mail.default');
        $ready = in_array($mailer, ['smtp', 'array'], true) && ($mailer !== 'array' || app()->environment(['local', 'testing']));
        $options = ['options' => ['min_range' => 0, 'max_range' => 2147483646]];
        $account = filter_var(config('notifications.account_per_minute'), FILTER_VALIDATE_INT, $options);
        $global = filter_var(config('notifications.global_per_minute'), FILTER_VALIDATE_INT, $options);
        // The reservation commits before SMTP is contacted: a queue retry or replayed job finds it already taken.
        $reserved = json_decode(DB::selectOne('SELECT app_account_notification_reserve_daily(?::uuid,?::boolean,?,?,?::boolean) AS data', [
            $id, $ready ? 'true' : 'false', $account === false ? null : $account, $global === false ? null : $global,
            $mailer === 'array' ? 'false' : 'true',
        ])->data, true, flags: JSON_THROW_ON_ERROR);
        if ($reserved['outcome'] !== 'reserved') {
            return;
        }
        try {
            Mail::mailer($mailer)->to($reserved['recipient'])->send((new BrandRequestOutcome($reserved['kind'], rtrim(config('app.url'), '/').'/brand/requests/'.$reserved['request'], $id))
                ->with('mail_budget_receipt', $reserved['mail_budget_receipt'] ?? null));
            $state = $mailer === 'array' ? 'captured' : 'sent';
        } catch (Throwable) {
            // The outcome is ambiguous and exception text can hold addresses or credentials: record
            // uncertainty for operator resolution and never resend automatically.
            $state = 'uncertain';
        }
        DB::select('SELECT app_account_notification_finish(?::uuid,?)', [$id, $state]);
    }
}
