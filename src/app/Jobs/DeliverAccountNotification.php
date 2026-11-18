<?php

namespace App\Jobs;

use App\Services\AccountNotifications;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Only the notification ID enters Redis/failed_jobs; the recipient is resolved at send reservation. */
class DeliverAccountNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 45;

    public function __construct(public string $notificationId) {}

    public function handle(AccountNotifications $notifications): void
    {
        $notifications->deliver($this->notificationId);
    }
}
