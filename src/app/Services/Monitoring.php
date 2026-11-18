<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * M6-03 monitoring. Measures aggregate health signals, keeps one alert state per check and announces changes.
 * Every value is a count, age or percentage: no workspace, account, recipient, payload or job argument is read
 * into a log line or an alert.
 */
class Monitoring
{
    /** What each check means and the unit of its value, for alert text. */
    public const CHECKS = [
        'redis' => ['Redis is unreachable', ''],
        'worker_heartbeat' => ['The queue worker has not completed its heartbeat job', 'seconds since the last heartbeat'],
        'queue_depth' => ['Jobs are accumulating on the queue', 'pending jobs'],
        'failed_jobs' => ['Background jobs failed in the last hour', 'failed jobs'],
        'outbox_lag' => ['Deliverable notifications are waiting in the outbox', 'seconds waited by the oldest'],
        'outbox_uncertain' => ['Notifications with an uncertain send need an operator resolution', 'unresolved notifications'],
        'mail_transport' => ['Notifications are deferred because mail or queue transport is unavailable', 'deferred notifications'],
        'delivery_budget' => ['Notifications are deferred by a delivery budget', 'deferred notifications'],
        'mail_budget_usage' => ['Email attempts are approaching the daily budget', 'percent of the allocation'],
        'schedule_lateness' => ['Due schedules have not been materialized', 'seconds overdue for the oldest'],
        'upload_scan_backlog' => ['Uploaded documents are waiting for a scan result', 'documents'],
        'db_connections' => ['Database connections are near the server limit', 'percent of the limit'],
        'disk_used' => ['Storage is filling up', 'percent used'],
        'http_errors' => ['Requests are failing with server errors', 'responses in the last five minutes'],
        'http_slow' => ['Requests are slow', 'responses in the last five minutes'],
    ];

    /**
     * Current value of every observable check. A check that cannot be observed is omitted, so it keeps
     * its last state instead of being reported healthy; the check for its dependency reports the outage.
     *
     * @return array<string, array{value: int|null, breached: bool}>
     */
    public function measure(): array
    {
        $snapshot = json_decode(DB::selectOne('SELECT app_monitor_snapshot() AS data')->data, true, flags: JSON_THROW_ON_ERROR);
        $values = [
            'worker_heartbeat' => $snapshot['worker_age'],
            'failed_jobs' => $snapshot['failed_jobs'],
            'outbox_lag' => $snapshot['outbox_lag'] ?? 0,
            'outbox_uncertain' => $snapshot['outbox_uncertain'],
            'mail_transport' => $snapshot['transport_deferred'],
            'delivery_budget' => $snapshot['budget_deferred'],
            'schedule_lateness' => $snapshot['schedule_late'] ?? 0,
            'upload_scan_backlog' => $snapshot['uploads_pending'],
            'db_connections' => (int) floor(100 * $snapshot['db_connections'] / max(1, $snapshot['db_connection_limit'])),
        ];
        $mailBudget = json_decode(DB::selectOne('SELECT app_mail_budget_snapshot() AS data')->data, true, flags: JSON_THROW_ON_ERROR);
        if ($mailBudget['enabled']) {
            $values['mail_budget_usage'] = (int) floor(100 * max(
                $mailBudget['rolling_total'] / $mailBudget['total_limit'],
                $mailBudget['rolling_business'] / $mailBudget['business_limit'],
                $mailBudget['rolling_account'] / $mailBudget['account_limit'],
            ));
        }

        $path = (string) config('monitoring.disk_path');
        $total = @disk_total_space($path);
        $free = @disk_free_space($path);
        if ($total && $free !== false) {
            $values['disk_used'] = (int) floor(100 * ($total - $free) / $total);
        }

        try {
            Redis::connection()->ping();
            $values['redis'] = 0;
            $values['queue_depth'] = Queue::connection('redis')->size((string) config('queue.connections.redis.queue', 'default'));
            $values += $this->requestCounts();
        } catch (Throwable) {
            $values['redis'] = 1;
        }

        $measured = [];
        foreach (config('monitoring.checks') as $name => $check) {
            if (! array_key_exists($name, $values) || ! isset(self::CHECKS[$name])) {
                continue;
            }
            $value = $values[$name];
            // A heartbeat that was never recorded is unknown, which is not healthy.
            $measured[$name] = ['value' => $value, 'breached' => $value === null ? $name === 'worker_heartbeat' : $value >= $check['limit']];
        }

        return $measured;
    }

    /**
     * Evaluate every check, record its state and announce changes.
     *
     * @return array<string, array{value: int|null, breached: bool, action: string}>
     */
    public function check(): array
    {
        $results = [];
        foreach ($this->measure() as $name => $result) {
            $check = config('monitoring.checks.'.$name);
            $action = DB::selectOne('SELECT app_monitor_record(?,?,?,?,?) AS action', [$name, $result['breached'] ? 'true' : 'false', $result['value'], (int) $check['hold'], (int) config('monitoring.repeat')])->action;
            if ($action !== 'none') {
                $this->announce($action, $name, $result['value'], (int) $check['limit']);
            }
            $results[$name] = [...$result, 'action' => $action];
        }
        $this->ping();

        return $results;
    }

    /** Count one finished request in the current minute. Never throws: telemetry must not break a response. */
    public function countRequest(int $status, float $milliseconds): void
    {
        try {
            $key = 'monitor:http:'.gmdate('YmdHi');
            Redis::connection()->pipeline(function ($pipe) use ($key, $status, $milliseconds) {
                $pipe->hincrby($key, 'total', 1);
                if ($status >= 500) {
                    $pipe->hincrby($key, 'errors', 1);
                }
                if ($milliseconds >= config('monitoring.http.slow_ms')) {
                    $pipe->hincrby($key, 'slow', 1);
                }
                $pipe->expire($key, 900);
            });
        } catch (Throwable) {
            // Redis being unreachable is reported by its own check.
        }
    }

    /** @return array{http_errors: int, http_slow: int} */
    private function requestCounts(): array
    {
        $errors = $slow = 0;
        for ($minute = 0; $minute < config('monitoring.http.window_minutes'); $minute++) {
            $bucket = Redis::connection()->hgetall('monitor:http:'.gmdate('YmdHi', time() - 60 * $minute));
            $errors += (int) ($bucket['errors'] ?? 0);
            $slow += (int) ($bucket['slow'] ?? 0);
        }

        return ['http_errors' => $errors, 'http_slow' => $slow];
    }

    private function announce(string $action, string $name, ?int $value, int $limit): void
    {
        $context = ['monitor' => $name, 'action' => $action, 'value' => $value, 'limit' => $limit];
        $action === 'fire' ? Log::critical('monitor.alert', $context) : Log::warning('monitor.alert', $context);

        $recipients = config('monitoring.recipients');
        if (! $recipients) {
            return;
        }
        [$meaning, $unit] = self::CHECKS[$name];
        $heading = ['fire' => 'ALERT', 'remind' => 'STILL FIRING', 'recover' => 'RECOVERED'][$action];
        $lines = [
            $heading.': '.$meaning.'.',
            '',
            'Check: '.$name,
            'Value: '.($value ?? 'unknown').($unit ? ' ('.$unit.')' : ''),
            'Alerts at: '.$limit,
            'Environment: '.config('app.name').' / '.config('app.env'),
            'Observed: '.gmdate('Y-m-d H:i:s').' UTC',
            '',
            'Diagnostics: '.rtrim((string) config('app.url'), '/').'/admin/operations',
        ];
        try {
            Mail::mailer(config('monitoring.mailer'))->raw(implode("\n", $lines), function ($message) use ($recipients, $heading, $name) {
                $message->to($recipients)->subject('['.config('app.name').' '.config('app.env').'] '.$heading.': '.$name);
            });
        } catch (Throwable $exception) {
            // The alert is already logged; a reminder follows after the repeat interval while it stays firing.
            Log::error('monitor.mail_failed', ['monitor' => $name, 'action' => $action, 'exception' => $exception::class]);
        }
    }

    /** Dead-man signal: an outside service alerts when these requests stop arriving. */
    private function ping(): void
    {
        $url = config('monitoring.ping_url');
        if (! $url) {
            return;
        }
        try {
            Http::timeout(5)->get($url);
        } catch (Throwable $exception) {
            Log::warning('monitor.ping_failed', ['exception' => $exception::class]);
        }
    }
}
