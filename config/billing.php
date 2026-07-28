<?php

use App\Services\Billing\Gateways\FakePaymentGateway;
use App\Services\Billing\Gateways\SslCommerzPaymentGateway;

return [
    'default_gateway' => env('BILLING_DEFAULT_GATEWAY', 'fake'),

    /** @var list<string> Lowercase provider slugs enabled for checkout. */
    'enabled_gateways' => array_values(array_filter(array_map(
        static fn (string $slug): string => strtolower(trim($slug)),
        explode(',', (string) env('BILLING_ENABLED_GATEWAYS', 'fake')),
    ))),

    /** @var array<string, class-string> Provider slug to gateway implementation. */
    'gateways' => [
        'fake' => FakePaymentGateway::class,
        'sslcommerz' => SslCommerzPaymentGateway::class,
    ],

    'order_expiry_minutes' => (int) env('BILLING_ORDER_EXPIRY_MINUTES', 30),
    'processing_timeout_minutes' => (int) env('BILLING_PROCESSING_TIMEOUT_MINUTES', 15),
    'webhook_retention_days' => (int) env('BILLING_WEBHOOK_RETENTION_DAYS', 90),

    'checkout' => [
        'allowed_redirect_hosts' => array_values(array_unique(array_filter([
            parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST),
            ...array_map('trim', explode(',', (string) env('BILLING_ALLOWED_REDIRECT_HOSTS', 'app.test'))),
        ]))),
        'allow_relative_redirects' => filter_var(env('BILLING_ALLOW_RELATIVE_REDIRECTS', true), FILTER_VALIDATE_BOOL),
        'allow_same_plan_renewal' => filter_var(env('BILLING_ALLOW_SAME_PLAN_RENEWAL', true), FILTER_VALIDATE_BOOL),
        'session_resume_window_minutes' => (int) env('BILLING_CHECKOUT_RESUME_WINDOW_MINUTES', 30),
        'order_expiry_minutes' => (int) env('BILLING_ORDER_EXPIRY_MINUTES', 30),
        'expiry_batch_size' => (int) env('BILLING_CHECKOUT_EXPIRY_BATCH_SIZE', 100),
    ],

    'reconciliation' => [
        'enabled' => filter_var(env('BILLING_RECONCILIATION_ENABLED', true), FILTER_VALIDATE_BOOL),
        'batch_size' => (int) env('BILLING_RECONCILIATION_BATCH_SIZE', 100),
    ],

    'queues' => [
        'provider_events' => env('BILLING_QUEUE_PROVIDER_EVENTS', 'default'),
        'activation' => env('BILLING_QUEUE_ACTIVATION', 'default'),
        'reconciliation' => env('BILLING_QUEUE_RECONCILIATION', 'default'),
    ],

    'callbacks' => [
        'max_payload_bytes' => (int) env('BILLING_CALLBACK_MAX_BYTES', 262144),
        'max_fields' => (int) env('BILLING_CALLBACK_MAX_FIELDS', 100),
        'max_field_bytes' => (int) env('BILLING_CALLBACK_MAX_FIELD_BYTES', 4096),
    ],
    'fake' => [
        'require_signature' => filter_var(env('BILLING_FAKE_REQUIRE_SIGNATURE', false), FILTER_VALIDATE_BOOL),
        'callback_secret' => env('BILLING_FAKE_CALLBACK_SECRET', 'fake-testing-secret'),
    ],
    'webhook_security' => [
        'enabled' => filter_var(env('BILLING_WEBHOOK_SECURITY_ENABLED', true), FILTER_VALIDATE_BOOL),
        'environment' => env('BILLING_WEBHOOK_ENVIRONMENT', env('APP_ENV', 'production')),
        'max_payload_bytes' => (int) env('BILLING_CALLBACK_MAX_BYTES', 262144),
        'max_canonical_payload_bytes' => (int) env('BILLING_WEBHOOK_MAX_CANONICAL_BYTES', 524288),
        'default_replay_window_seconds' => (int) env('BILLING_WEBHOOK_REPLAY_WINDOW_SECONDS', 300),
        'allowed_future_clock_skew_seconds' => (int) env('BILLING_WEBHOOK_FUTURE_SKEW_SECONDS', 60),
        'nonce_retention_minutes' => (int) env('BILLING_WEBHOOK_NONCE_RETENTION_MINUTES', 1440),
        'security_failure_retention_days' => (int) env('BILLING_WEBHOOK_FAILURE_RETENTION_DAYS', 30),
        'prune_batch_size' => (int) env('BILLING_WEBHOOK_PRUNE_BATCH_SIZE', 500),
        'max_candidate_signing_keys' => (int) env('BILLING_WEBHOOK_MAX_CANDIDATE_KEYS', 3),
        'allowed_content_types' => ['application/json', 'application/x-www-form-urlencoded'],
        'rate_limits' => ['per_provider_per_minute' => 300, 'per_ip_per_minute' => 120, 'invalid_signature_per_ip_per_minute' => 20],
        'providers' => [
            'fake' => [
                'enabled' => filter_var(env('FAKE_PAYMENT_WEBHOOK_ENABLED', false), FILTER_VALIDATE_BOOL),
                'signature_version' => 'v1', 'algorithm' => 'sha256',
                'canonicalization' => 'timestamp_nonce_raw_body',
                'key_id' => env('FAKE_PAYMENT_WEBHOOK_KEY_ID', 'config-v1'),
                'secret' => env('FAKE_PAYMENT_WEBHOOK_SECRET', ''),
                'required_headers' => ['x-fake-signature', 'x-fake-timestamp', 'x-fake-nonce'],
                'allowed_source_ips' => array_values(array_filter(array_map('trim', explode(',', (string) env('FAKE_PAYMENT_WEBHOOK_ALLOWED_IPS', ''))))),
            ],
            'stripe' => ['enabled' => false],
            'sslcommerz' => [
                'enabled' => filter_var(env('SSLCOMMERZ_ENABLED', false), FILTER_VALIDATE_BOOL),
                'required_headers' => [],
                'allowed_source_ips' => array_values(array_filter(array_map('trim', explode(',', (string) env('SSLCOMMERZ_ALLOWED_IPS', ''))))),
            ],
            'bkash' => ['enabled' => false],
            'nagad' => ['enabled' => false],
        ],
    ],
    'sslcommerz' => [
        'enabled' => filter_var(env('SSLCOMMERZ_ENABLED', false), FILTER_VALIDATE_BOOL),
        'environment' => env('SSLCOMMERZ_ENV', 'sandbox'),
        'default_store' => env('SSLCOMMERZ_DEFAULT_STORE', 'default'),
        'stores' => [
            'default' => [
                'enabled' => filter_var(env('SSLCOMMERZ_DEFAULT_STORE_ENABLED', true), FILTER_VALIDATE_BOOL),
                'environment' => env('SSLCOMMERZ_DEFAULT_STORE_ENV', env('SSLCOMMERZ_ENV', 'sandbox')),
                'store_id' => env('SSLCOMMERZ_STORE_ID'),
                'store_password' => env('SSLCOMMERZ_STORE_PASSWORD'),
            ],
        ],
        'allowed_currencies' => array_values(array_filter(array_map(
            static fn (string $currency): string => strtoupper(trim($currency)),
            explode(',', (string) env('SSLCOMMERZ_ALLOWED_CURRENCIES', 'BDT')),
        ))),
        'maintenance_mode' => filter_var(env('SSLCOMMERZ_MAINTENANCE_MODE', false), FILTER_VALIDATE_BOOL),
        'api' => [
            'sandbox_base_url' => env('SSLCOMMERZ_SANDBOX_BASE_URL', 'https://sandbox.sslcommerz.com'),
            'production_base_url' => env('SSLCOMMERZ_PRODUCTION_BASE_URL', 'https://securepay.sslcommerz.com'),
            'timeout_seconds' => (int) env('SSLCOMMERZ_TIMEOUT_SECONDS', 30),
            'connect_timeout_seconds' => (int) env('SSLCOMMERZ_CONNECT_TIMEOUT_SECONDS', 10),
        ],
        'validation' => [
            'retry_attempts' => (int) env('SSLCOMMERZ_VALIDATION_RETRY_ATTEMPTS', 3),
        ],
        'checkout' => [
            'product_name' => env('SSLCOMMERZ_PRODUCT_NAME', 'SaaS subscription'),
            'product_category' => env('SSLCOMMERZ_PRODUCT_CATEGORY', 'software'),
            'support_phone' => env('SSLCOMMERZ_SUPPORT_PHONE', ''),
            'neutral_address' => env('SSLCOMMERZ_NEUTRAL_ADDRESS', 'Not applicable'),
            'neutral_city' => env('SSLCOMMERZ_NEUTRAL_CITY', 'Dhaka'),
            'neutral_country' => env('SSLCOMMERZ_NEUTRAL_COUNTRY', 'Bangladesh'),
        ],
        'health_check' => [
            'enabled' => filter_var(env('SSLCOMMERZ_HEALTH_CHECK_ENABLED', true), FILTER_VALIDATE_BOOL),
            'cache_ttl_seconds' => (int) env('SSLCOMMERZ_HEALTH_CHECK_CACHE_TTL_SECONDS', 60),
        ],
    ],
    'payment_sync' => [
        'enabled' => filter_var(env('BILLING_PAYMENT_SYNC_ENABLED', true), FILTER_VALIDATE_BOOL),
        'stale_after_minutes' => (int) env('BILLING_PAYMENT_SYNC_STALE_MINUTES', 10),
        'batch_size' => (int) env('BILLING_PAYMENT_SYNC_BATCH_SIZE', 100),
        'max_attempts_per_order' => (int) env('BILLING_PAYMENT_SYNC_MAX_ATTEMPTS', 12),
    ],
];
