<?php

use App\Services\Billing\Gateways\FakePaymentGateway;

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
];
