<?php

return [
    'recommended_plan_slug' => env('COMMERCIAL_RECOMMENDED_PLAN_SLUG', 'premium'),

    /** @var list<int> Percent thresholds for one-time usage notifications. */
    'usage_thresholds' => [80, 90, 100],

    /**
     * User-visible finite features exposed on GET /api/v1/commercial/usage.
     *
     * @var array<string, 'period'|'inventory'>
     */
    'summary_features' => [
        'outbound_messages_per_period' => 'period',
        'outbound_recipients_per_period' => 'period',
        'outbound_attachment_bytes_per_period' => 'period',
        'max_api_keys' => 'inventory',
        'webhook.max_endpoints' => 'inventory',
        'inbox.max_active' => 'inventory',
    ],
];
