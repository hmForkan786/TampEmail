<?php

return [
    'thresholds' => [
        'failure_rate' => (float) env('INBOUND_METRICS_FAILURE_RATE_THRESHOLD', 0.10),
        'queue_backlog' => (int) env('INBOUND_METRICS_QUEUE_BACKLOG_THRESHOLD', 100),
        'pending_scan_age_minutes' => (int) env('INBOUND_METRICS_PENDING_SCAN_AGE_MINUTES', 30),
        'retry_exhaustion' => (int) env('INBOUND_METRICS_RETRY_EXHAUSTION_THRESHOLD', 1),
    ],
];
