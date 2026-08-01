<?php

declare(strict_types=1);

return [
    'max_delivery_attempts' => (int) env('WEBHOOK_MAX_DELIVERY_ATTEMPTS', 5),
    'connect_timeout_seconds' => (float) env('WEBHOOK_CONNECT_TIMEOUT_SECONDS', 5),
    'response_excerpt_limit' => (int) env('WEBHOOK_RESPONSE_EXCERPT_LIMIT', 512),
];
