<?php

namespace App\Services;

use App\Exceptions\MailBudgetExceeded;
use Illuminate\Database\Connection;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Mailer\Envelope;

class MailBudget
{
    /** An outer HTTP/tenant rollback cannot refund an SMTP attempt. */
    private function connection(): Connection
    {
        if (! config('database.connections.mail_budget')) {
            config(['database.connections.mail_budget' => DB::connection()->getConfig()]);
        }
        $connection = DB::connection('mail_budget');
        if ($connection->transactionLevel() !== 0 || $connection->getDriverName() !== 'pgsql') {
            throw new RuntimeException('Mail accounting requires an independent PostgreSQL connection.');
        }

        return $connection;
    }

    public function reserve(string $category, int $recipients = 1): array
    {
        return json_decode($this->connection()->selectOne('SELECT app_mail_budget_reserve(?,?) AS data', [$category, $recipients], false)->data,
            true, flags: JSON_THROW_ON_ERROR);
    }

    /** Includes direct verification/reset and monitoring mail as well as both outboxes. */
    public function sending(MessageSending $event): void
    {
        $mailer = $event->data['mailer'] ?? config('mail.default');
        $transport = config('mail.mailers.'.$mailer.'.transport');
        if (in_array($transport, ['array', 'log'], true)) {
            return;
        }
        $recipients = Envelope::create($event->message)->getRecipients();
        $receipt = $event->data['mail_budget_receipt'] ?? null;
        if ($receipt === null) {
            $reservation = $this->reserve($event->data['mail_budget_category'] ?? 'account', count($recipients));
            if ($reservation['status'] === 'disabled') {
                return;
            }
            if ($reservation['status'] !== 'reserved') {
                throw new MailBudgetExceeded;
            }
            $receipt = $reservation['receipt'];
        }
        if ($transport !== 'smtp') {
            // Composite transports can make several provider attempts for a single Laravel event.
            // The approved pilot transport is SMTP; alternate transports need their own accounting review.
            throw new RuntimeException('The active pilot mail budget requires the SMTP transport.');
        }
        if (! $this->connection()->selectOne('SELECT app_mail_budget_consume(?::uuid,?) AS allowed', [$receipt, count($recipients)], false)->allowed) {
            throw new RuntimeException('Mail reservation is unavailable.');
        }
    }
}
