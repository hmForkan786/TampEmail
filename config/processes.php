<?php
return [
    'heartbeat_ttl_seconds' => (int) env('PROCESS_HEARTBEAT_TTL_SECONDS', 180),
    'heartbeat_write_interval_seconds' => (int) env('PROCESS_HEARTBEAT_WRITE_INTERVAL_SECONDS', 30),
    'heartbeat_record_limit' => (int) env('PROCESS_HEARTBEAT_RECORD_LIMIT', 16),
    'instance_id' => env('PROCESS_INSTANCE_ID'),
    'failed_jobs_threshold' => (int) env('PROCESS_FAILED_JOBS_THRESHOLD', 10),
    'backlog_threshold' => (int) env('PROCESS_QUEUE_BACKLOG_THRESHOLD', 100),
    'oldest_job_age_seconds' => (int) env('PROCESS_OLDEST_JOB_AGE_SECONDS', 900),
    'worker_count' => (int) env('QUEUE_WORKER_COUNT', 1),
    'timeout' => (int) env('QUEUE_WORKER_TIMEOUT', 110),
    'sleep' => (int) env('QUEUE_WORKER_SLEEP', 3),
    'tries' => (int) env('QUEUE_WORKER_TRIES', 3),
    'max_jobs' => (int) env('QUEUE_WORKER_MAX_JOBS', 0),
    'max_time' => (int) env('QUEUE_WORKER_MAX_TIME', 0),
    'memory' => (int) env('QUEUE_WORKER_MEMORY', 512),
];
