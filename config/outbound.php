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

    'mailer' => env('OUTBOUND_MAILER', env('MAIL_MAILER', 'smtp')),

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
    | Reply / forward policy
    |--------------------------------------------------------------------------
    */

    'reply_allow_cc' => (bool) env('OUTBOUND_REPLY_ALLOW_CC', true),

    'require_idempotency_key' => (bool) env('OUTBOUND_REQUIRE_IDEMPOTENCY_KEY', true),

];
