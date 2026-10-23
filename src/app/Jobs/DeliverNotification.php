<?php

namespace App\Jobs;

use App\Services\NotificationOutbox;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Only scalar routing IDs enter Redis/failed_jobs; capabilities stay encrypted in PostgreSQL. */
class DeliverNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 45;

    public function __construct(public int $actorId, public string $orgId, public string $notificationId) {}

    public function handle(NotificationOutbox $outbox): void
    {
        $outbox->deliver($this->actorId, $this->orgId, $this->notificationId);
    }
}
