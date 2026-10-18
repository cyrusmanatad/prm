<?php

namespace Tests\Acceptance;

use App\Models\User;
use App\Services\Workspaces;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Fixtures\CrashProbeJob;
use Tests\Fixtures\TenantProbeJob;

class WorkerCrashTest extends AcceptanceCase
{
    public function test_sigkill_rolls_back_and_reserved_job_recovers_without_duplicate_committed_audit(): void
    {
        $queue = 'm1-crash-'.Str::uuid();
        $marker = $queue.':started';
        $a = User::factory()->create();
        $b = User::factory()->create();
        $service = app(Workspaces::class);
        $orgA = $service->create($a->id, 'Before crash');
        $orgB = $service->create($b->id, 'Other tenant');
        $connection = Queue::connection('redis');
        $first = $second = null;
        try {
            $connection->push(new CrashProbeJob($a->id, $orgA, $marker), '', $queue);
            $first = $this->worker($queue, 1);
            $this->eventually(fn () => Redis::get($marker) !== null, 'Worker did not reach the uncommitted-write barrier.');
            $started = json_decode(Redis::get($marker), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame(1, $started['attempt']);
            $first->signal(SIGKILL);
            $first->wait();
            $this->assertTrue($first->hasBeenSignaled());
            $this->assertSame(SIGKILL, $first->getTermSignal());
            $this->eventually(fn () => $this->control->selectOne('SELECT count(*) AS count FROM pg_stat_activity WHERE pid = ?', [$started['pid']])->count === 0,
                'Killed worker retained a PostgreSQL backend.');
            $this->assertSame('Before crash', $service->listing($a->id)[0]->name);
            $this->assertSame(0, $this->control->table('audit_logs')->where('org_id', $orgA)->where('event', 'workspace.renamed')->count());
            $this->assertSame(1, $connection->size($queue));

            // Same real queue, no manual release: the replacement reclaims the expired reservation.
            $connection->push(new TenantProbeJob($b->id, $orgB), '', $queue);
            $second = $this->worker($queue, 2);
            $second->wait();
            $this->assertTrue($second->isSuccessful(), $second->getOutput().$second->getErrorOutput());
            $this->assertSame(2, json_decode(Redis::get($marker), true, flags: JSON_THROW_ON_ERROR)['attempt']);
            $this->assertSame('Recovered worker', $service->listing($a->id)[0]->name);
            $this->assertSame('Worker processed', $service->listing($b->id)[0]->name);
            foreach ([$orgA, $orgB] as $org) {
                $this->assertSame(1, $this->control->table('audit_logs')->where('org_id', $org)->where('event', 'workspace.renamed')->count());
            }
            $this->assertSame(0, $connection->size($queue));
            $this->assertSame(0, DB::table('failed_jobs')->where('queue', $queue)->count());
        } finally {
            $first?->stop(0);
            $second?->stop(0);
            $connection->clear($queue);
            Redis::del($marker, $marker.':release');
            DB::table('failed_jobs')->where('queue', $queue)->delete();
        }
    }

    private function worker(string $queue, int $jobs): Process
    {
        $worker = new Process([
            PHP_BINARY, 'artisan', 'queue:work', 'redis', '--queue='.$queue,
            '--max-jobs='.$jobs, '--max-time=12', '--tries=3', '--sleep=1', '--timeout=3', '--no-interaction',
        ], base_path(), [
            'APP_ENV' => 'testing', 'REDIS_QUEUE_RETRY_AFTER' => '4',
            'TEST_MIGRATION_DB_PASSWORD' => false,
        ]);
        $worker->setTimeout(20);
        $worker->start();

        return $worker;
    }
}
