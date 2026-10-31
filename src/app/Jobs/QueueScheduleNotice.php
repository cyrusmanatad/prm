<?php

namespace App\Jobs;

use App\Services\ScheduledNotifications;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class QueueScheduleNotice implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $actorId, public string $orgId, public string $scheduleId, public string $runId) {}

    public function handle(ScheduledNotifications $notices): void
    {
        $notices->consume($this->actorId, $this->orgId, $this->scheduleId, $this->runId);
    }
}
