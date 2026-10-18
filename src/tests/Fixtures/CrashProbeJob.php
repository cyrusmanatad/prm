<?php

namespace Tests\Fixtures;

use App\Jobs\Middleware\TenantJobContext;
use App\Services\Workspaces;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/** A test barrier lets the parent kill the worker after writes but before commit. */
class CrashProbeJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $actorId, public string $orgId, public string $marker) {}

    public function middleware(): array
    {
        return [new TenantJobContext];
    }

    public function handle(Workspaces $workspaces): void
    {
        $workspaces->rename($this->actorId, $this->orgId, 'Recovered worker');
        Redis::setex($this->marker, 60, json_encode([
            'attempt' => $this->attempts(), 'pid' => DB::selectOne('SELECT pg_backend_pid() AS pid')->pid,
        ], JSON_THROW_ON_ERROR));
        if ($this->attempts() === 1) {
            Redis::blpop([$this->marker.':release'], 15);
        }
    }
}
