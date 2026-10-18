<?php

namespace Tests\Acceptance;

use App\Models\User;
use App\Services\Workspaces;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class SchedulerRecoveryTest extends AcceptanceCase
{
    public function test_task_exception_releases_overlap_lock_and_fresh_run_dispatches_a_tenant_job(): void
    {
        $marker = 'm1-schedule-'.Str::uuid();
        $actor = User::factory()->create();
        $org = app(Workspaces::class)->create($actor->id, 'Before schedule');
        $process = null;
        try {
            $process = $this->schedule($marker, 'fail', $actor->id, $org);
            $this->finish($process);
            $this->assertSame('1', (string) Redis::get($marker.':failures'));
            $this->assertSame(0, Queue::connection('redis')->size($marker));
            $process = $this->schedule($marker, 'dispatch', $actor->id, $org);
            $this->finish($process);
            $this->assertSame('1', (string) Redis::get($marker.':dispatched'));
            $this->consume($marker);
            $this->assertSame('Worker processed', app(Workspaces::class)->listing($actor->id)[0]->name);
        } finally {
            $process?->stop(0);
            $this->cleanup($marker);
        }
    }

    public function test_sigkill_blocks_overlapping_runs_until_real_mutex_ttl_expires_then_restart_recovers(): void
    {
        $marker = 'm1-schedule-'.Str::uuid();
        $actor = User::factory()->create();
        $org = app(Workspaces::class)->create($actor->id, 'Before scheduler crash');
        $first = $other = null;
        $mutex = '';
        try {
            $first = $this->schedule($marker, 'hold', $actor->id, $org);
            $this->eventually(fn () => Redis::get($marker.':entered') !== null, 'Scheduled task did not enter its barrier.');
            $mutex = json_decode(explode("\n", $first->getOutput())[0], true, flags: JSON_THROW_ON_ERROR)['mutex'];
            $this->assertTrue($this->locked($mutex));
            $other = $this->schedule($marker, 'dispatch', $actor->id, $org);
            $this->finish($other);
            $this->assertSame('1', (string) Redis::get($marker.':entered'));
            $first->signal(SIGKILL);
            $first->wait();
            $this->assertSame(SIGKILL, $first->getTermSignal());
            $other = $this->schedule($marker, 'dispatch', $actor->id, $org);
            $this->finish($other);
            $this->assertNull(Redis::get($marker.':dispatched'));
            $this->assertTrue($this->locked($mutex));
            // Use the real one-minute TTL; never delete or shorten a live mutex.
            $this->eventually(fn () => ! $this->locked($mutex), 'Killed scheduler mutex did not expire.', seconds: 70);
            $other = $this->schedule($marker, 'dispatch', $actor->id, $org);
            $this->finish($other);
            $this->assertSame('1', (string) Redis::get($marker.':dispatched'));
            $this->consume($marker);
            $this->assertSame(1, $this->control->table('audit_logs')->where('org_id', $org)->where('event', 'workspace.renamed')->count());
        } finally {
            $first?->stop(0);
            $other?->stop(0);
            if ($mutex !== '') {
                Cache::store('redis')->lock($mutex)->forceRelease();
            }
            $this->cleanup($marker);
        }
    }

    private function schedule(string $marker, string $mode, int $actor, string $org): Process
    {
        $process = new Process([PHP_BINARY, 'tests/Fixtures/scheduler-probe.php', $marker, $mode, (string) $actor, $org], base_path(), [
            'APP_ENV' => 'testing', 'CACHE_STORE' => 'redis', 'TEST_MIGRATION_DB_PASSWORD' => false,
        ]);
        $process->setTimeout(20);
        $process->start();

        return $process;
    }

    private function locked(string $mutex): bool
    {
        return ! Cache::store('redis')->lock($mutex, 60)->get(fn () => true);
    }

    private function finish(Process $process): void
    {
        $process->wait();
        $this->assertTrue($process->isSuccessful(), $process->getOutput().$process->getErrorOutput());
    }

    private function consume(string $queue): void
    {
        $worker = new Process([
            PHP_BINARY, 'artisan', 'queue:work', 'redis', '--queue='.$queue,
            '--stop-when-empty', '--tries=1', '--no-interaction',
        ], base_path(), ['TEST_MIGRATION_DB_PASSWORD' => false]);
        $worker->setTimeout(15);
        $worker->start();
        $this->finish($worker);
        $this->assertSame(0, Queue::connection('redis')->size($queue));
    }

    private function cleanup(string $marker): void
    {
        Queue::connection('redis')->clear($marker);
        Redis::del($marker.':entered', $marker.':failures', $marker.':dispatched', $marker.':release');
    }
}
