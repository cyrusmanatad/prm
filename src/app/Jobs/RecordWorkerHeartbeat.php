<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Dispatched by the scheduler every minute and recorded when a worker runs it, so the age of the worker
 * observation covers both a stopped worker and a queue too slow to reach the job. Carries no tenant data.
 */
class RecordWorkerHeartbeat implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** A stopped worker leaves one heartbeat waiting, not one per minute. */
    public int $uniqueFor = 600;

    public function handle(): void
    {
        DB::select("SELECT app_record_observation('worker')");
    }
}
