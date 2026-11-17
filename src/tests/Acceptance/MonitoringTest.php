<?php

namespace Tests\Acceptance;

use App\Jobs\RecordWorkerHeartbeat;
use App\Services\Monitoring;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

class MonitoringTest extends AcceptanceCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->control->table('monitor_checks')->delete();
        config(['monitoring.recipients' => ['alerts@example.test'], 'monitoring.mailer' => 'array', 'monitoring.ping_url' => null, 'monitoring.repeat' => 3600]);
    }

    private function record(string $name, bool $breached, int $hold = 0, int $repeat = 3600): string
    {
        return DB::selectOne('SELECT app_monitor_record(?,?,?,?,?) AS action', [$name, $breached ? 'true' : 'false', 7, $hold, $repeat])->action;
    }

    private function sent(): array
    {
        return array_map(fn ($message) => $message->getOriginalMessage(), app('mail.manager')->mailer('array')->getSymfonyTransport()->messages()->all());
    }

    public function test_the_runtime_role_reaches_alert_state_only_through_the_functions(): void
    {
        $this->assertSame('fire', $this->record('synthetic_check', true));
        $this->assertSame(1, $this->control->table('monitor_checks')->count());
        // Forced row security with no policy: the runtime role sees and changes nothing directly.
        $this->assertSame(0, DB::table('monitor_checks')->count());
        $this->assertSame(0, DB::table('monitor_checks')->update(['state' => 'ok']));
        $this->assertSame(0, DB::table('monitor_checks')->delete());
        try {
            DB::table('monitor_checks')->insert(['name' => 'forged_check']);
            $this->fail('The runtime role inserted alert state directly.');
        } catch (QueryException $exception) {
            $this->assertSame('42501', $exception->getCode());
        }
        $this->assertSame('firing', $this->control->table('monitor_checks')->value('state'));
    }

    public function test_a_check_fires_after_its_hold_reminds_after_the_repeat_and_recovers_once(): void
    {
        // Breached, but not yet for the hold period.
        $this->assertSame('none', $this->record('synthetic_check', true, hold: 120));
        $this->control->table('monitor_checks')->update(['breached_since' => now()->subSeconds(119)]);
        $this->assertSame('none', $this->record('synthetic_check', true, hold: 120));
        $this->control->table('monitor_checks')->update(['breached_since' => now()->subSeconds(121)]);
        $this->assertSame('fire', $this->record('synthetic_check', true, hold: 120));
        $this->assertSame('none', $this->record('synthetic_check', true, hold: 120));

        $this->control->table('monitor_checks')->update(['notified_at' => now()->subSeconds(3601)]);
        $this->assertSame('remind', $this->record('synthetic_check', true, hold: 120));
        $this->assertSame('none', $this->record('synthetic_check', true, hold: 120));
        // Reminders are off when the repeat interval is zero.
        $this->control->table('monitor_checks')->update(['notified_at' => now()->subDays(2)]);
        $this->assertSame('none', $this->record('synthetic_check', true, hold: 120, repeat: 0));

        $this->assertSame('recover', $this->record('synthetic_check', false, hold: 120));
        $this->assertSame('none', $this->record('synthetic_check', false, hold: 120));
        $row = $this->control->table('monitor_checks')->first();
        $this->assertSame(['ok', null, null, 7], [$row->state, $row->breached_since, $row->notified_at, $row->value]);

        // A breach that clears before the hold never alerts and starts its hold again next time.
        $this->assertSame('none', $this->record('synthetic_check', true, hold: 120));
        $this->assertSame('none', $this->record('synthetic_check', false, hold: 120));
        $this->control->table('monitor_checks')->update(['breached_since' => null]);
        $this->assertSame('none', $this->record('synthetic_check', true, hold: 120));
    }

    public function test_invalid_evaluations_and_unknown_observation_sources_are_rejected(): void
    {
        foreach ([['Bad Name', 'true', 1, 0, 0], ['synthetic_check', null, 1, 0, 0], ['synthetic_check', 'true', 1, -1, 0], ['synthetic_check', 'true', 1, 0, -1]] as $arguments) {
            try {
                DB::selectOne('SELECT app_monitor_record(?,?,?,?,?) AS action', $arguments);
                $this->fail('An invalid evaluation was recorded.');
            } catch (QueryException $exception) {
                $this->assertContains($exception->getCode(), ['22000', '23514']);
            }
        }
        $this->assertSame(0, $this->control->table('monitor_checks')->count());
        foreach (['monitor', 'scanner', null] as $source) {
            try {
                DB::select('SELECT app_record_observation(?)', [$source]);
                $this->fail('An unknown observation source was recorded.');
            } catch (QueryException $exception) {
                $this->assertSame('22000', $exception->getCode());
            }
        }
    }

    public function test_the_snapshot_holds_only_aggregate_numbers(): void
    {
        $snapshot = json_decode(DB::selectOne('SELECT app_monitor_snapshot() AS data')->data, true);
        $this->assertEqualsCanonicalizing(['observed_at', 'scheduler_age', 'worker_age', 'outbox_lag', 'outbox_uncertain', 'transport_deferred', 'budget_deferred', 'failed_jobs',
            'schedule_late', 'uploads_pending', 'db_connections', 'db_connection_limit', 'db_bytes'], array_keys($snapshot));
        unset($snapshot['observed_at']);
        foreach ($snapshot as $key => $value) {
            $this->assertTrue($value === null || is_int($value), $key.' is not a number');
        }
        $this->assertGreaterThan(0, $snapshot['db_connections']);
        $this->assertGreaterThan(0, $snapshot['db_connection_limit']);
    }

    public function test_the_worker_heartbeat_is_recorded_by_the_job_and_a_missing_one_is_not_healthy(): void
    {
        $this->control->table('platform_observations')->where('source', 'worker')->delete();
        $measured = app(Monitoring::class)->measure();
        $this->assertSame(['value' => null, 'breached' => true], $measured['worker_heartbeat']);

        (new RecordWorkerHeartbeat)->handle();
        $measured = app(Monitoring::class)->measure();
        $this->assertFalse($measured['worker_heartbeat']['breached']);
        $this->assertLessThan(5, $measured['worker_heartbeat']['value']);

        $this->control->table('platform_observations')->where('source', 'worker')->update(['observed_at' => now()->subSeconds(config('monitoring.checks.worker_heartbeat.limit') + 5)]);
        $this->assertTrue(app(Monitoring::class)->measure()['worker_heartbeat']['breached']);
        $this->control->table('platform_observations')->where('source', 'worker')->delete();
    }

    public function test_a_failed_job_alerts_once_by_mail_without_job_content_and_then_recovers(): void
    {
        $recent = $this->control->table('failed_jobs')->where('failed_at', '>', now()->subHour())->count();
        config(['monitoring.checks.failed_jobs.limit' => $recent + 1]);
        Http::fake();
        $monitoring = app(Monitoring::class);
        $this->assertSame('none', $monitoring->check()['failed_jobs']['action']);

        $uuid = (string) Str::uuid();
        $this->control->table('failed_jobs')->insert(['uuid' => $uuid, 'connection' => 'redis', 'queue' => 'default', 'failed_at' => now()->subMinute(),
            'payload' => json_encode(['displayName' => 'App\\Jobs\\DeliverNotification', 'data' => 'PRIVATE-PAYLOAD-MARKER']), 'exception' => 'RuntimeException: PRIVATE-EXCEPTION-MARKER']);
        try {
            $result = $monitoring->check()['failed_jobs'];
            $this->assertSame(['value' => $recent + 1, 'breached' => true, 'action' => 'fire'], $result);
            $this->assertSame('none', $monitoring->check()['failed_jobs']['action']);
        } finally {
            $this->control->table('failed_jobs')->where('uuid', $uuid)->delete();
        }
        $this->assertSame('recover', $monitoring->check()['failed_jobs']['action']);
        $this->assertSame('none', $monitoring->check()['failed_jobs']['action']);

        $mail = array_values(array_filter($this->sent(), fn ($message) => str_contains($message->getSubject(), 'failed_jobs')));
        $this->assertCount(2, $mail);
        $this->assertStringContainsString('ALERT: failed_jobs', $mail[0]->getSubject());
        $this->assertStringContainsString('RECOVERED: failed_jobs', $mail[1]->getSubject());
        $this->assertSame('alerts@example.test', $mail[0]->getTo()[0]->getAddress());
        $body = $mail[0]->getTextBody();
        $this->assertStringContainsString('Value: '.($recent + 1).' (failed jobs)', $body);
        $this->assertStringContainsString('/admin/operations', $body);
        foreach ($this->sent() as $message) {
            $this->assertStringNotContainsString('PRIVATE', $message->getTextBody().$message->getSubject());
            $this->assertStringNotContainsString('DeliverNotification', $message->getTextBody());
        }
        // The dead-man ping is not configured here, so nothing left the application.
        Http::assertNothingSent();
    }

    public function test_alerts_are_logged_only_without_recipients_and_the_dead_man_ping_follows_each_evaluation(): void
    {
        config(['monitoring.recipients' => [], 'monitoring.ping_url' => 'https://ping.example.test/synthetic', 'monitoring.checks.disk_used.limit' => 0]);
        Http::fake(['ping.example.test/*' => Http::response('', 200)]);
        $this->assertSame('fire', app(Monitoring::class)->check()['disk_used']['action']);
        $this->assertSame([], $this->sent());
        Http::assertSentCount(1);

        // A failing ping never stops the evaluation.
        Http::fake(fn () => throw new \RuntimeException('unreachable'));
        $this->assertSame('none', app(Monitoring::class)->check()['disk_used']['action']);
    }

    public function test_requests_get_a_server_generated_id_and_are_counted_without_their_url(): void
    {
        config(['monitoring.http.enabled' => true, 'monitoring.http.slow_ms' => 0]);
        $key = 'monitor:http:'.gmdate('YmdHi');
        $before = Redis::connection()->hgetall($key);
        $response = $this->withHeader('X-Request-Id', 'client-supplied')->get('/login');
        $response->assertOk();
        $this->assertTrue(Str::isUuid($response->headers->get('X-Request-Id')));
        $next = 'monitor:http:'.gmdate('YmdHi');
        $after = Redis::connection()->hgetall($next);
        $base = $next === $key ? $before : [];
        $this->assertSame((int) ($base['total'] ?? 0) + 1, (int) $after['total']);
        $this->assertSame((int) ($base['slow'] ?? 0) + 1, (int) $after['slow']);
        $this->assertSame((int) ($base['errors'] ?? 0), (int) ($after['errors'] ?? 0));
        $this->assertGreaterThan(0, Redis::connection()->ttl($next));

        // The liveness probe is not counted.
        $this->get('/up')->assertOk();
        $this->assertSame((int) $after['total'], (int) Redis::connection()->hgetall($next)['total']);
        $this->assertGreaterThanOrEqual(1, app(Monitoring::class)->measure()['http_slow']['value']);
    }
}
