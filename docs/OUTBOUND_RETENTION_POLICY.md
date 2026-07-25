# Outbound Retention, Deletion, and Privacy Lifecycle

This policy governs `outbound_messages`, their delivery attempts, provider
events, attachment references, and recipient suppressions. It is separate
from `docs/INBOUND_RETENTION_POLICY.md` (inbound email/attachments) and
`config/retention.php` (API/audit log retention, cleaned up by
`logs:cleanup`). Outbound audit log rows (`outbound.*` actions in
`audit_logs`) are **not** deleted by this policy or by `outbound:prune`;
their lifecycle is `config/retention.php` / `logs:cleanup`, out of scope
here.

## Default periods

| Data | Default | Bounds | Config key |
| --- | ---: | ---: | --- |
| Free-plan message content | 1 day | 1-3650 | `OUTBOUND_RETENTION_FREE_DAYS` |
| Premium-plan message content | 30 days | 1-3650 | `OUTBOUND_RETENTION_PREMIUM_DAYS` |
| Delivery attempts | 90 days | 1-3650 | `OUTBOUND_ATTEMPT_RETENTION_DAYS` |
| Provider events | 90 days | 1-3650 | `OUTBOUND_PROVIDER_EVENT_RETENTION_DAYS` |
| Audit rows (reference only; not pruned here) | 365 days | 1-3650 | `OUTBOUND_AUDIT_RETENTION_DAYS` |

Invalid or zero values disable that category (fail closed) exactly like
`config/inbound_retention.php` — a bad value never means "delete
everything," it means "never prune this category." `OUTBOUND_RETENTION_CLEANUP_ENABLED`
gates all mutation; when false (the default), `outbound:prune` only ever
reports counts and never mutates, regardless of `--confirm`.

A user's actual content-retention window is resolved per user by
`OutboundRetentionPolicy::contentRetentionDays()`:

1. Plan entitlement `outbound_retention_days` via
   `EntitlementService::featureValue()`, expected shape `['days' => N]`.
2. Otherwise, the plan's free/premium default from this config.

## User deletion ("hide")

`DeleteOutboundMessageAction` lets the **owner only** hide a message from
their own list/detail views:

- Sets `user_deleted_at`; never rewrites transport state.
- A still-`queued` message is cancelled first via
  `CancelOutboundMessageAction` (the same rule the cancel affordance
  uses), then hidden — deletion alone never leaves a message silently
  sending in the background.
- `sending`/`sent`/`delivered`/`failed`/`cancelled` messages are hidden
  as-is: deletion never cancels an in-flight or completed send, and never
  claims a delivery outcome it didn't have.
- Shared source attachments (the inbound `Attachment` rows/files
  referenced by `attachment_ids`) are never touched.
- Audited as `outbound.message_user_deleted` with the state before/after
  hide and whether a cancel happened first.
- Hard deletion of the row is **not** immediate; it only ever happens
  later, and only via `outbound:prune`, once content is redacted and no
  delivery-attempt/provider-event children remain (see below).

`OutboundMessageListingService` and `OutboundMessageAccessService::findOwned()`
(used by both the web and API controllers, and by both attachment-download
controllers) exclude `user_deleted_at IS NOT NULL` for normal owner
queries. Admin/ops Filament pages (`OutboundMessageTimelinePage`,
`OutboundRecipientSuppressions`, `OutboundEmailOps`) query
`OutboundMessage` directly and are **not** scoped by this column — hiding
a message from its owner does not restore or remove admin visibility.

Endpoints: `DELETE /api/v1/outbound-messages/{message}` (scope
`outbound_messages:write`) and `DELETE /outbound-messages/{message}`
(web, CSRF + `@method('DELETE')`), plus a **Delete** button on the
message detail page when `OutboundMessageAccessService::canDelete()` is
true (i.e. not already hidden).

## Content redaction

Once a message is older than its resolved content-retention window (and
not under an active retention hold), `OutboundPruneService` redacts it in
place — the row and its safe operational metadata survive, only content
is cleared:

- `subject` → `'[redacted]'`.
- `text_body`, `html_body` → `null`.
- `from_display_name` → `null`.
- `to_recipients` / `cc_recipients` / `bcc_recipients` → reduced to
  `sha256:<hash>` entries (never left as plaintext addresses); the
  recipient **count** is preserved.
- `attachment_ids` → `null`. This only clears the outbound *reference*;
  the inbound `Attachment` row and its stored file are never deleted by
  this policy (they are shared, inbound-owned objects — see below).
- `content_redacted_at` is set.
- Preserved: `id`, `state`, all lifecycle timestamps, `failure_code`,
  `provider_message_id`, `attempt_count` — everything needed for
  operational/audit reconstruction without exposing content.
- Audited as `outbound.content_redacted`.

`OutboundDeliveryAttempt` never stores body, full recipient lists, raw
provider responses, or attachment content in the first place (see its
model docblock), so no secondary-table recipient redaction is needed for
attempts.

## Attachment cleanup

Outbound messages never own attachment storage; `attachment_ids` is only
a reference into the sender's own inbound `Attachment` rows (see
`OutboundMessageAccessService::listSafeAttachments()`). Retention pruning
therefore only ever clears the outbound reference array
(`attachment_ids = null`) during content redaction — it **never** deletes
the referenced `Attachment` row or its file, because that row may still
be a legitimate inbound message attachment independent of any outbound
send. There is no separate outbound attachment staging copy today (the
outbound send pipeline streams directly from the inbound attachment); if
a staging copy is introduced in a future prompt, this document must be
updated to describe its independent, idempotent cleanup with no
storage-path logging. Any prune-related lookups against storage treat a
missing object as already-clean (idempotent) and never log a path.

## Delivery attempts and provider events

- `OutboundDeliveryAttempt` rows older than `attempt_days` are deleted in
  bounded batches, skipping any row whose message is under an active
  retention hold.
- `OutboundProviderEvent` rows older than `provider_event_days` are
  deleted the same way. Provider events unrelated to any message
  (terminal-unmatched events with `outbound_message_id = null`) are
  pruned by age alone.
- Deleting a provider event never touches
  `outbound_recipient_suppressions`: a complaint/bounce suppression is a
  fully independent row with its own lifecycle (see below), never
  cascaded from — or dependent on — the provider event that originally
  caused it.
- Deleting old attempts/events never rewrites or removes the message's
  own state/timestamps, so the safe delivery-state audit trail on the
  message itself is unaffected.

## Suppression retention

`outbound_recipient_suppressions` rows are **never** pruned by
`outbound:prune`, and never expire merely because an originating message
ages out or is pruned:

- Permanent suppressions (`reason` = `complaint` or `permanent_bounce`,
  `expires_at = null`) are indefinite. They can only be removed via
  `OutboundSuppressionService::unsuppress()`, which requires elevated
  authorization for complaint/provider-sourced suppressions.
- Time-bounded suppressions (non-null `expires_at`) become inactive once
  expired, per `OutboundRecipientSuppression::isCurrentlyActive()` /
  `OutboundSuppressionService::isSuppressed()` — this is an *activity*
  check, not a deletion. Rows are not deleted by this policy even after
  expiry; a separate, explicit suppression-housekeeping decision (not
  implemented here) would be required to hard-delete expired/inactive
  suppression rows, and it is explicitly out of scope for this prompt.

## Hard delete of message metadata

Deliberately the most conservative category, and the last one run per
prune pass. A message row is only ever hard-deleted when **all** of the
following hold:

- `user_deleted_at` is set (the owner explicitly hid it — never a message
  they never deleted).
- `content_redacted_at` is set (content already cleared).
- `state` is `delivered`, `cancelled`, or `failed` (never `draft`,
  `queued`, `sending`, or `sent` — a `sent` message may still receive a
  delivery event and must not be removed while that is possible).
- No active retention hold.
- No remaining `OutboundDeliveryAttempt` or `OutboundProviderEvent` child
  rows (i.e. those categories have already cleared for this message,
  which in practice means enough time has passed for both attempt and
  provider-event retention to also have elapsed).

Audited as `outbound.message_hard_deleted` (message id + state only).

## Legal/security hold

Admin-only (`isPlatformAdmin()`), via `CreateOutboundRetentionHoldAction`
/ `ReleaseOutboundRetentionHoldAction`:

- Sets `retention_hold_until` (nullable — `null` means indefinite, until
  explicitly released) and `retention_hold_reason_code`, a fixed code
  from `App\Enums\OutboundRetentionHoldReasonCode` (`legal_hold`,
  `security_investigation`, `regulatory_request`, `other`) — never free
  text.
- Blocks every prune category (content redaction, attempt/event pruning,
  hard delete) for that message.
- Does **not** restore user visibility for a message the owner already
  hid; a hold and a user-hide are independent.
- Audited as `outbound.retention_hold_set` / `outbound.retention_hold_released`.

## `outbound:prune` command

```
php artisan outbound:prune {--dry-run} {--confirm} {--batch=}
```

- Dry-run by default, and whenever `--confirm` is absent: reports safe,
  metadata-only counts (`eligible_*`) and mutates nothing.
- `--confirm` performs the bounded prune. Batch size defaults to
  `OUTBOUND_RETENTION_BATCH_SIZE` (bounded to 1-1000).
- Fail-closed: when `OUTBOUND_RETENTION_CLEANUP_ENABLED` is false, the
  command is always a no-op report (`blocked: yes`, `blocked_reason:
  disabled`) regardless of flags.
- A named `Cache::lock('outbound:prune', ...)` prevents overlapping runs;
  a concurrent invocation exits immediately with a warning and does not
  error.
- Idempotent: re-running against the same data is safe — already
  redacted/pruned/held/deleted rows are simply not selected again.
- Output never includes a message body, recipient address, secret, or
  storage path — only counts, states, and durations.
- Registered in the scheduler (`bootstrap/app.php`) as
  `outbound:prune --confirm` with `withoutOverlapping()->daily()`, only
  when `outbound_retention.cleanup_enabled` is true (mirrors the
  `inbound:cleanup` scheduler gate).

### Report fields

`eligible_content_redaction`, `content_redacted`, `eligible_attempts`,
`attempts_deleted`, `eligible_provider_events`, `provider_events_deleted`,
`eligible_hard_delete`, `messages_hard_deleted`, `held`, `skipped`,
`failed`, `blocked`, `blocked_reason`, `duration`.

## Explicitly out of scope

Per the originating prompt, this policy does **not** implement: full user
data export, complete account deletion, legal advice, automatic
plan/billing changes, deletion of an active suppression, deletion of
audit records contrary to policy (see `config/retention.php` instead), or
any direct storage-path admin tooling.
