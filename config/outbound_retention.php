<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Outbound retention, deletion, and privacy lifecycle
|--------------------------------------------------------------------------
|
| Fail-closed like config/inbound_retention.php: an invalid or zero value
| disables that category (it never means "delete everything"). Nothing in
| this file deletes anything by itself; it only shapes what
| OutboundPruneService and OutboundRetentionPolicy are allowed to act on.
|
| - free_days / premium_days govern when message CONTENT (subject, body,
|   display names, recipients, attachment references) is redacted. The
|   message row itself, its state, and safe operational metadata survive
|   redaction; only content is cleared.
| - A user's actual retention is resolved per-user through
|   EntitlementService::featureValue($user, 'outbound_retention_days'),
|   which may return ['days' => N] from their plan. When no entitlement
|   value is present, free/premium plan defaults below apply.
| - provider_event_days / attempt_days bound how long delivery-attempt and
|   provider-event rows survive independent of message content retention.
| - audit_days is documentation-only for this command: outbound audit log
|   rows are covered by the existing logs:cleanup / config/retention.php
|   pipeline, never by outbound:prune.
| - Suppressions (config/outbound suppression table) are never pruned by
|   age of an unrelated message. Permanent complaint/bounce suppressions
|   are indefinite; only a suppression's own expires_at/active fields
|   determine its lifecycle. See docs/OUTBOUND_RETENTION_POLICY.md.
|
*/

return [

    'free_days' => ((int) env('OUTBOUND_RETENTION_FREE_DAYS', 1) >= 1 && (int) env('OUTBOUND_RETENTION_FREE_DAYS', 1) <= 3650)
        ? (int) env('OUTBOUND_RETENTION_FREE_DAYS', 1)
        : 0,

    'premium_days' => ((int) env('OUTBOUND_RETENTION_PREMIUM_DAYS', 30) >= 1 && (int) env('OUTBOUND_RETENTION_PREMIUM_DAYS', 30) <= 3650)
        ? (int) env('OUTBOUND_RETENTION_PREMIUM_DAYS', 30)
        : 0,

    'provider_event_days' => ((int) env('OUTBOUND_PROVIDER_EVENT_RETENTION_DAYS', 90) >= 1 && (int) env('OUTBOUND_PROVIDER_EVENT_RETENTION_DAYS', 90) <= 3650)
        ? (int) env('OUTBOUND_PROVIDER_EVENT_RETENTION_DAYS', 90)
        : 0,

    'attempt_days' => ((int) env('OUTBOUND_ATTEMPT_RETENTION_DAYS', 90) >= 1 && (int) env('OUTBOUND_ATTEMPT_RETENTION_DAYS', 90) <= 3650)
        ? (int) env('OUTBOUND_ATTEMPT_RETENTION_DAYS', 90)
        : 0,

    // Documentation/reference only. Outbound audit rows are cleaned up by
    // the existing logs:cleanup command per config/retention.php, never by
    // outbound:prune.
    'audit_days' => ((int) env('OUTBOUND_AUDIT_RETENTION_DAYS', 365) >= 1 && (int) env('OUTBOUND_AUDIT_RETENTION_DAYS', 365) <= 3650)
        ? (int) env('OUTBOUND_AUDIT_RETENTION_DAYS', 365)
        : 0,

    'batch_size' => max(1, min(1000, (int) env('OUTBOUND_RETENTION_BATCH_SIZE', 500))),

    'dry_run_default' => true,

    'retention_hold_supported' => true,

    'cleanup_enabled' => filter_var(env('OUTBOUND_RETENTION_CLEANUP_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

    // Plan entitlement key resolved via EntitlementService::featureValue();
    // expected shape ['days' => N] when present.
    'feature_key' => 'outbound_retention_days',

    /*
    |--------------------------------------------------------------------------
    | Legal/security hold reason codes
    |--------------------------------------------------------------------------
    |
    | Fixed, stable codes only — never free text — so audit metadata and
    | reporting stay bounded and safe. See App\Enums\OutboundRetentionHoldReasonCode.
    */
    'hold_reason_codes' => ['legal_hold', 'security_investigation', 'regulatory_request', 'other'],

];
