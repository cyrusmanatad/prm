<?php

namespace Tests\Fixtures;

use App\Jobs\Middleware\TenantJobContext;
use App\Services\Workspaces;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use LogicException;

/** Disposable integration probe; never dispatched by application routes. */
class TenantProbeJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $actorId, public string $orgId, public bool $fail = false) {}

    public function middleware(): array
    {
        return [new TenantJobContext];
    }

    public function handle(Workspaces $workspaces): void
    {
        $workspaces->rename($this->actorId, $this->orgId, 'Worker processed');
        if ($this->fail) {
            throw new LogicException('Intentional M1 queue probe failure');
        }
    }
}
