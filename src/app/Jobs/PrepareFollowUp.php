<?php

namespace App\Jobs;

use App\Services\FollowUpDrafts;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class PrepareFollowUp implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $actorId, public string $orgId, public string $scheduleId, public string $runId) {}

    public function handle(FollowUpDrafts $drafts): void
    {
        $drafts->consume($this->actorId, $this->orgId, $this->scheduleId, $this->runId);
    }
}
