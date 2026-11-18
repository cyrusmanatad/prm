<?php

// M6-03. Thresholds are proposals for a small pilot, not measured capacity limits; tune them from observed behavior.
// 'limit' is the value at which a check is breached; 'hold' is how long it must stay breached before it alerts.
return [
    // Comma-separated addresses that receive alerts. Empty: alerts are logged only.
    'recipients' => array_values(array_filter(array_map('trim', explode(',', (string) env('MONITORING_ALERT_TO', ''))))),
    'mailer' => env('MONITORING_MAILER', env('TRANSACTIONAL_MAIL_MAILER', env('MAIL_MAILER', 'log'))),

    // Requested after every completed evaluation so an outside service can alert when the monitor itself stops.
    'ping_url' => env('MONITORING_PING_URL'),

    // Seconds between reminders while a check stays firing. Zero disables reminders.
    'repeat' => (int) env('MONITORING_REPEAT_SECONDS', 21600),

    'http' => [
        'enabled' => (bool) env('MONITORING_HTTP', true),
        'slow_ms' => (int) env('MONITORING_SLOW_MS', 2000),
        'window_minutes' => 5,
    ],

    'disk_path' => env('MONITORING_DISK_PATH', storage_path()),

    'checks' => [
        'redis' => ['limit' => 1, 'hold' => 120],
        'worker_heartbeat' => ['limit' => (int) env('MONITORING_WORKER_AGE', 240), 'hold' => 120],
        'queue_depth' => ['limit' => (int) env('MONITORING_QUEUE_DEPTH', 200), 'hold' => 300],
        'failed_jobs' => ['limit' => (int) env('MONITORING_FAILED_JOBS', 1), 'hold' => 0],
        'outbox_lag' => ['limit' => (int) env('MONITORING_OUTBOX_LAG', 600), 'hold' => 0],
        'outbox_uncertain' => ['limit' => 1, 'hold' => 0],
        'mail_transport' => ['limit' => 1, 'hold' => 300],
        'delivery_budget' => ['limit' => 1, 'hold' => 600],
        'schedule_lateness' => ['limit' => (int) env('MONITORING_SCHEDULE_LATE', 600), 'hold' => 0],
        'upload_scan_backlog' => ['limit' => 1, 'hold' => 900],
        'db_connections' => ['limit' => (int) env('MONITORING_DB_CONNECTIONS_PERCENT', 80), 'hold' => 180],
        'disk_used' => ['limit' => (int) env('MONITORING_DISK_PERCENT', 85), 'hold' => 0],
        'http_errors' => ['limit' => (int) env('MONITORING_HTTP_ERRORS', 5), 'hold' => 0],
        'http_slow' => ['limit' => (int) env('MONITORING_HTTP_SLOW', 10), 'hold' => 0],
    ],
];
