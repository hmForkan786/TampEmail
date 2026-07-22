<?php

return [

    'rate_limits' => [
        'web_per_minute' => (int) env('RATE_LIMIT_WEB_PER_MINUTE', 120),
        'api_per_minute' => (int) env('RATE_LIMIT_API_PER_MINUTE', 60),
        'inbox_creation_per_hour' => (int) env('RATE_LIMIT_INBOX_CREATION_PER_HOUR', 20),
        'ingestion_per_minute' => (int) env('RATE_LIMIT_INGESTION_PER_MINUTE', 300),
    ],

];
