<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Outbound email feature switches
    |--------------------------------------------------------------------------
    |
    | Global and per-operation kill switches. All default to false until the
    | send/reply/forward foundations are enabled in later prompts.
    |
    */

    'enabled' => (bool) env('OUTBOUND_ENABLED', false),

    'send_enabled' => (bool) env('OUTBOUND_SEND_ENABLED', false),

    'reply_enabled' => (bool) env('OUTBOUND_REPLY_ENABLED', false),

    'forward_enabled' => (bool) env('OUTBOUND_FORWARD_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Transport
    |--------------------------------------------------------------------------
    |
    | unavailable — fail closed (default)
    | array — Laravel array mailer (tests)
    | smtp / mail — configured mailer adapters (Prompt 602+)
    |
    */

    'transport' => env('OUTBOUND_TRANSPORT', 'unavailable'),

    /*
    |--------------------------------------------------------------------------
    | Delivery provider identity (Prompt 611)
    |--------------------------------------------------------------------------
    |
    | Identifies which vendor adapter correlates provider events with accepted
    | SMTP submissions. Supported: generic, ses. Unsupported values fail closed
    | for provider-event readiness (transport may still use smtp).
    |
    */

    'provider' => strtolower((string) env('OUTBOUND_PROVIDER', 'generic')),

    /*
    | Dedicated outbound mailer name. Defaults to the dedicated "outbound"
    | mailer (see config/mail.php). Do not silently inherit MAIL_MAILER=log.
    */
    'mailer' => env('OUTBOUND_MAILER', 'outbound'),

    /*
    |--------------------------------------------------------------------------
    | Dedicated SMTP settings (production outbound)
    |--------------------------------------------------------------------------
    |
    | Used by the dedicated "outbound" mailer. Empty credentials are never
    | treated as valid when require_auth is true. Passwords must only live in
    | environment / secret stores — never in logs or audit metadata.
    |
    */

    'smtp' => [
        'host' => env('OUTBOUND_SMTP_HOST'),
        'port' => ($port = filter_var(env('OUTBOUND_SMTP_PORT', 587), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 65535],
        ])) === false ? 587 : $port,
        'username' => env('OUTBOUND_SMTP_USERNAME'),
        'password' => env('OUTBOUND_SMTP_PASSWORD'),
        'encryption' => strtolower((string) env('OUTBOUND_SMTP_ENCRYPTION', 'tls')),
        'timeout' => ($timeout = filter_var(env('OUTBOUND_SMTP_TIMEOUT', 30), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 300],
        ])) === false ? 30 : $timeout,
        'local_domain' => env('OUTBOUND_SMTP_LOCAL_DOMAIN'),
        'verify_peer' => filter_var(env('OUTBOUND_SMTP_VERIFY_PEER', true), FILTER_VALIDATE_BOOL),
        'require_auth' => filter_var(env('OUTBOUND_SMTP_REQUIRE_AUTH', true), FILTER_VALIDATE_BOOL),
    ],

    /*
    |--------------------------------------------------------------------------
    | Limits
    |--------------------------------------------------------------------------
    */

    'max_recipients_per_message' => (int) env('OUTBOUND_MAX_RECIPIENTS', 20),

    'max_subject_length' => (int) env('OUTBOUND_MAX_SUBJECT_LENGTH', 998),

    'max_text_body_bytes' => (int) env('OUTBOUND_MAX_TEXT_BODY_BYTES', 102400),

    'max_html_body_bytes' => (int) env('OUTBOUND_MAX_HTML_BODY_BYTES', 204800),

    'max_attachments_per_message' => (int) env('OUTBOUND_MAX_ATTACHMENTS', 10),

    'max_attachment_bytes' => (int) env('OUTBOUND_MAX_ATTACHMENT_BYTES', 10485760),

    'max_total_attachment_bytes' => (int) env('OUTBOUND_MAX_TOTAL_ATTACHMENT_BYTES', 26214400),

    'messages_per_hour' => (int) env('OUTBOUND_MESSAGES_PER_HOUR', 30),

    'messages_per_day' => (int) env('OUTBOUND_MESSAGES_PER_DAY', 200),

    'messages_per_minute' => (int) env('OUTBOUND_MESSAGES_PER_MINUTE', 5),

    'unique_recipients_per_hour' => (int) env('OUTBOUND_UNIQUE_RECIPIENTS_PER_HOUR', 100),

    'unique_recipients_per_day' => (int) env('OUTBOUND_UNIQUE_RECIPIENTS_PER_DAY', 500),

    'concurrent_queued_messages' => (int) env('OUTBOUND_CONCURRENT_QUEUED', 20),

    'outbound_bytes_per_day' => (int) env('OUTBOUND_BYTES_PER_DAY', 104857600),

    'abuse' => [
        'fail_closed_on_quota_backend' => filter_var(env('OUTBOUND_ABUSE_FAIL_CLOSED', true), FILTER_VALIDATE_BOOL),
        'temp_block_hours' => (int) env('OUTBOUND_ABUSE_TEMP_BLOCK_HOURS', 24),
        'bounce_threshold_24h' => (int) env('OUTBOUND_ABUSE_BOUNCE_THRESHOLD', 10),
        'complaint_threshold_24h' => (int) env('OUTBOUND_ABUSE_COMPLAINT_THRESHOLD', 2),
        'failed_send_threshold_24h' => (int) env('OUTBOUND_ABUSE_FAILED_THRESHOLD', 25),
        'suppression_block_threshold_24h' => (int) env('OUTBOUND_ABUSE_SUPPRESSION_BLOCK_THRESHOLD', 20),
    ],

    /*
    |--------------------------------------------------------------------------
    | Delivery retries (Prompt 605)
    |--------------------------------------------------------------------------
    */

    'send_max_attempts' => (int) env('OUTBOUND_SEND_MAX_ATTEMPTS', 3),

    'send_backoff_seconds' => array_values(array_filter(array_map(
        static fn (string $value): int => (int) trim($value),
        explode(',', (string) env('OUTBOUND_SEND_BACKOFF_SECONDS', '60,300,900')),
    ), static fn (int $value): bool => $value > 0)) ?: [60, 300, 900],

    /*
    |--------------------------------------------------------------------------
    | Worker deployment (Prompt 613)
    |--------------------------------------------------------------------------
    |
    | Bounded, isolated worker counts per outbound queue plus the required
    | timeout ordering: SMTP timeout < job timeout < queue retry_after.
    | Validated by OutboundWorkerConfigValidator without sending mail.
    |
    */

    'worker' => [
        'delivery_count' => (int) env('OUTBOUND_DELIVERY_WORKER_COUNT', 1),
        'events_count' => (int) env('OUTBOUND_EVENTS_WORKER_COUNT', 1),
        'maintenance_count' => (int) env('OUTBOUND_MAINTENANCE_WORKER_COUNT', 1),
        'job_timeout_seconds' => (int) env('OUTBOUND_WORKER_TIMEOUT_SECONDS', 60),
        'sleep_seconds' => (int) env('OUTBOUND_WORKER_SLEEP_SECONDS', 3),
        'tries' => (int) env('OUTBOUND_WORKER_TRIES', 3),
        'backoff_seconds' => (int) env('OUTBOUND_WORKER_BACKOFF_SECONDS', 30),
        'memory_mb' => (int) env('OUTBOUND_WORKER_MEMORY_MB', 512),
    ],

    /*
    |--------------------------------------------------------------------------
    | Stale sending / provider-event reconciliation (Prompt 613)
    |--------------------------------------------------------------------------
    |
    | A message stuck in `sending` past the threshold means the delivery
    | worker died mid-attempt. Requeue is only safe when the transport was
    | never invoked for the stuck attempt; otherwise the outcome is
    | ambiguous and must wait for a provider event or admin reconciliation
    | — never blindly resent.
    */

    'reconciliation' => [
        'stale_sending_threshold_seconds' => (int) env('OUTBOUND_STALE_SENDING_THRESHOLD_SECONDS', 900),
        'stale_sending_batch_size' => (int) env('OUTBOUND_STALE_SENDING_BATCH_SIZE', 50),
        'unmatched_event_window_hours' => (int) env('OUTBOUND_UNMATCHED_EVENT_WINDOW_HOURS', 24),
        'unmatched_event_batch_size' => (int) env('OUTBOUND_UNMATCHED_EVENT_BATCH_SIZE', 50),
    ],

    /*
    |--------------------------------------------------------------------------
    | Reply / forward policy
    |--------------------------------------------------------------------------
    */

    'reply_allow_cc' => (bool) env('OUTBOUND_REPLY_ALLOW_CC', true),

    'require_idempotency_key' => (bool) env('OUTBOUND_REQUIRE_IDEMPOTENCY_KEY', true),

    'ops' => [
        'oldest_queued_seconds_threshold' => (int) env('OUTBOUND_OPS_OLDEST_QUEUED_SECONDS', 600),
        'failed_last_hour_threshold' => (int) env('OUTBOUND_OPS_FAILED_LAST_HOUR', 5),
        'temporary_failure_rate_threshold' => (int) env('OUTBOUND_OPS_TEMPORARY_FAILURE_RATE', 10),
        'complaint_spike_threshold' => (int) env('OUTBOUND_OPS_COMPLAINT_SPIKE', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Domain authentication readiness (Prompt 612)
    |--------------------------------------------------------------------------
    |
    | SPF + DKIM are mandatory when enforce=true. Weak/missing DMARC yields
    | degraded (send allowed). Invalid mandatory records fail closed.
    | Never auto-modifies public DNS. Never stores private DKIM keys.
    |
    */

    'domain_authentication' => [
        'enforce' => filter_var(env('OUTBOUND_DOMAIN_AUTH_ENFORCE', true), FILTER_VALIDATE_BOOL),
        'allow_degraded_dmarc' => filter_var(env('OUTBOUND_DOMAIN_AUTH_ALLOW_DEGRADED_DMARC', true), FILTER_VALIDATE_BOOL),
        'dns_timeout_seconds' => (int) env('OUTBOUND_DOMAIN_AUTH_DNS_TIMEOUT', 3),
        'recheck_interval_seconds' => (int) env('OUTBOUND_DOMAIN_AUTH_RECHECK_INTERVAL', 3600),
        'manual_recheck_cooldown_seconds' => (int) env('OUTBOUND_DOMAIN_AUTH_MANUAL_COOLDOWN', 60),
        'batch_size' => (int) env('OUTBOUND_DOMAIN_AUTH_BATCH_SIZE', 50),
        'ses' => [
            'spf_include' => env('OUTBOUND_SES_SPF_INCLUDE', 'include:amazonses.com'),
            'dkim_tokens' => env('OUTBOUND_SES_DKIM_TOKENS', ''),
            'dkim_cname_suffix' => env('OUTBOUND_SES_DKIM_CNAME_SUFFIX', 'dkim.amazonses.com'),
            'ownership_prefix' => env('OUTBOUND_SES_OWNERSHIP_PREFIX', 'temail-domain-verification='),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Delivery webhook / provider events (Prompt 607)
    |--------------------------------------------------------------------------
    |
    | SMTP acceptance alone never marks messages delivered. Only verified
    | provider events may transition sent → delivered.
    |
    */

    'delivery_webhook' => [
        'timestamp_skew_seconds' => (int) env('OUTBOUND_DELIVERY_WEBHOOK_TIMESTAMP_SKEW_SECONDS', 300),
        'max_body_bytes' => (int) env('OUTBOUND_DELIVERY_WEBHOOK_MAX_BODY_BYTES', 65536),
        'rate_limit_per_minute' => (int) env('OUTBOUND_DELIVERY_WEBHOOK_RATE_LIMIT_PER_MINUTE', 60),
        'providers' => array_filter([
            'generic' => [
                'secret' => env('OUTBOUND_GENERIC_DELIVERY_WEBHOOK_SECRET'),
                'content_types' => ['application/json'],
                'transport_aliases' => [],
            ],
            // SES is always registered so the route exists; signature verification
            // fails closed without a valid SNS-signed payload / certificate.
            'ses' => [
                'topic_arn' => env('OUTBOUND_SES_SNS_TOPIC_ARN'),
                'cert_cache_ttl_seconds' => (int) env('OUTBOUND_SES_CERT_CACHE_TTL_SECONDS', 3600),
                'subscription_cache_ttl_seconds' => (int) env('OUTBOUND_SES_SUBSCRIPTION_CACHE_TTL_SECONDS', 3600),
                'auto_confirm_subscriptions' => false,
                'content_types' => ['application/json', 'text/plain'],
                'max_body_bytes' => (int) env('OUTBOUND_SES_WEBHOOK_MAX_BODY_BYTES', 262144),
                // SMTP relay submissions store transport driver names; allow correlation.
                'transport_aliases' => ['smtp', 'mail', 'ses', 'array'],
            ],
        ], static fn ($value) => is_array($value)),
    ],

];
