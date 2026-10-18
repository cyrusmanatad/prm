<?php

namespace Tests\Integration;

use App\Models\User;
use App\Services\Workspaces;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Fixtures\TenantProbeJob;
use Tests\TestCase;

/** Requires disposable PostgreSQL and Redis, with no other consumer on this queue. */
class RedisWorkerTest extends TestCase
{
    public function test_real_worker_records_failure_rolls_back_and_processes_another_tenant_after_restart(): void
    {
        $queue = 'm1-probe-'.Str::uuid();
        $a = User::factory()->create();
        $b = User::factory()->create();
        $workspaces = app(Workspaces::class);
        $orgA = $workspaces->create($a->id, 'A before worker');
        $orgB = $workspaces->create($b->id, 'B before worker');
        $connection = Queue::connection('redis');

        try {
            $connection->push(new TenantProbeJob($a->id, $orgA, true), '', $queue);
            $connection->push(new TenantProbeJob($b->id, $orgB), '', $queue);
            $this->runWorker($queue);
            $this->assertSame(0, $connection->size($queue));
            $this->assertSame(1, DB::table('failed_jobs')->where('queue', $queue)->count());
            $this->assertSame('A before worker', $workspaces->listing($a->id)[0]->name);
            $this->assertSame('Worker processed', $workspaces->listing($b->id)[0]->name);
            $this->assertRenameCount($a->id, $orgA, 0);
            $this->assertRenameCount($b->id, $orgB, 1);

            // A fresh worker must process the previously failed tenant normally.
            $connection->push(new TenantProbeJob($a->id, $orgA), '', $queue);
            $this->runWorker($queue);
            $this->assertSame(0, $connection->size($queue));
            $this->assertSame('Worker processed', $workspaces->listing($a->id)[0]->name);
            $this->assertRenameCount($a->id, $orgA, 1);
        } finally {
            $connection->clear($queue);
            DB::table('failed_jobs')->where('queue', $queue)->delete();
        }
    }

    private function runWorker(string $queue): void
    {
        // Inherit restricted runtime credentials; no migration credentials needed.
        $worker = new Process([
            PHP_BINARY, 'artisan', 'queue:work', 'redis', '--queue='.$queue,
            '--stop-when-empty', '--tries=1', '--sleep=0', '--timeout=20', '--no-interaction',
        ], base_path(), ['QUEUE_CONNECTION' => 'redis']);
        $worker->setTimeout(60);
        $worker->run();
        $this->assertTrue($worker->isSuccessful(), $worker->getOutput().$worker->getErrorOutput());
    }

    private function assertRenameCount(int $actorId, string $orgId, int $expected): void
    {
        app(TenantContext::class)->run($actorId, $orgId, function () use ($expected): void {
            $this->assertSame($expected, DB::table('audit_logs')->where('event', 'workspace.renamed')->count());
        });
    }
}
