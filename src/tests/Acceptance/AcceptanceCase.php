<?php

namespace Tests\Acceptance;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

abstract class AcceptanceCase extends TestCase
{
    protected Connection $control;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('weavdash_app', DB::selectOne('SELECT current_user AS name')->name);
        $password = getenv('TEST_MIGRATION_DB_PASSWORD');
        $this->assertNotEmpty($password, 'Use the disposable acceptance Compose service.');
        config(['database.connections.acceptance_control' => [
            ...config('database.connections.pgsql'), 'url' => null,
            'username' => 'weavdash_migrator', 'password' => $password,
        ]]);
        $this->control = DB::connection('acceptance_control');
        $this->control->statement("SET lock_timeout = '8s'");
    }

    protected function tearDown(): void
    {
        if (isset($this->control)) {
            while ($this->control->transactionLevel() > 0) {
                $this->control->rollBack();
            }
            DB::purge('acceptance_control');
        }
        parent::tearDown();
    }

    protected function eventually(callable $condition, string $message, float $seconds = 8): void
    {
        $deadline = microtime(true) + $seconds;
        do {
            if ($condition()) {
                $this->assertTrue(true);

                return;
            }
            usleep(20_000);
        } while (microtime(true) < $deadline);
        $this->fail($message);
    }

    protected function probe(string $action, int $actorId, string $orgId, bool $control = false): Process
    {
        $process = new Process([
            PHP_BINARY, 'tests/Fixtures/concurrent-membership.php', $action, (string) $actorId, $orgId,
        ], base_path(), [
            'APP_ENV' => 'testing', 'TEST_MIGRATION_DB_PASSWORD' => false,
            ...($control ? ['DB_USERNAME' => 'weavdash_migrator', 'DB_PASSWORD' => getenv('TEST_MIGRATION_DB_PASSWORD')] : []),
        ]);
        $process->setTimeout(15);
        $process->start();
        $this->eventually(fn () => str_contains($process->getOutput(), "\n"), 'Probe failed to connect: '.$process->getErrorOutput());

        return $process;
    }

    protected function assertBlocked(Process $process): void
    {
        $pid = json_decode(explode("\n", $process->getOutput())[0], true, flags: JSON_THROW_ON_ERROR)['pid'];
        $this->eventually(fn () => $this->control->selectOne('SELECT cardinality(pg_blocking_pids(?)) AS count', [$pid])->count > 0,
            'Expected a real PostgreSQL lock wait, not an elapsed-time assumption.');
        $this->assertTrue($process->isRunning());
    }

    protected function probeResult(Process $process): array
    {
        $process->wait();
        $this->assertTrue($process->isSuccessful(), $process->getOutput().$process->getErrorOutput());
        $lines = explode("\n", trim($process->getOutput()));

        return json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR);
    }
}
