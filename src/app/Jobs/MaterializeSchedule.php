<?php

namespace App\Jobs;

use App\Services\BusinessSchedules;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class MaterializeSchedule implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $actorId, public string $orgId, public string $scheduleId) {}

    public function handle(BusinessSchedules $schedules): void
    {
        $schedules->materialize($this->actorId, $this->orgId, $this->scheduleId);
    }
}
