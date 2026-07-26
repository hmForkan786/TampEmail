# Outbound notifications

Outbound lifecycle notifications are owner-scoped and content-free. Supported events:

| Event | Default in-app | Default email |
| --- | --- | --- |
| `outbound.queued` | yes | no |
| `outbound.sent` | yes | no |
| `outbound.delivered` | yes | no |
| `outbound.failed` | yes | yes |
| `outbound.cancelled` | yes | no |
| `outbound.scheduled` | yes | no |
| `outbound.schedule_deferred` | yes | no |
| `outbound.schedule_failed` | yes | yes |
| `outbound.usage_warning` | yes | yes |
| `outbound.usage_exhausted` | yes | yes |

Preferences are persisted in `outbound_notification_preferences`, are created lazily, and use a version integer for optimistic concurrency. `PATCH /api/v1/outbound-notification-preferences` requires `version`; stale changes return 409. Notification list/read/dismiss endpoints are protected by the existing outbound scopes and never expose another owner's rows.

API surface:

- `GET /api/v1/outbound-notifications?unread=1` — unread-only filter
- `GET /api/v1/outbound-notifications/unread-count` — unread badge count
- `POST /api/v1/outbound-notifications/read-all` — mark all read
- `POST /api/v1/outbound-notifications/{id}/read` — mark one read
- `DELETE /api/v1/outbound-notifications/{id}` — dismiss (soft)

The payload contains only event type, outbound public UUID, operation/state, safe failure category, retryable flag, schedule time, usage percentage, and generic summary. It never stores recipients, subject, bodies, attachment names, raw provider data, SMTP errors, or credentials.

## Event hooks

Notifications are emitted from existing outbound lifecycle code without creating side effects:

- **`outbound.queued`** — after a message transitions to `queued` from draft submit, direct send create, send-now, or scheduled dispatch (`queued:{messageId}`).
- **`outbound.sent` / `outbound.failed` / `outbound.delivered`** — from delivery job and provider event processor (`sent:{id}`, `failed:{id}`, `delivered:{id}`, etc.).
- **`outbound.cancelled` / `outbound.scheduled`** — from cancel and schedule actions.
- **`outbound.schedule_deferred`** — when the scheduled dispatcher temporarily defers (`schedule_deferred:{messageId}:{reason}:{Y-m-d}`). The daily bucket prevents a defer notification every dispatch minute for the same reason.
- **`outbound.schedule_failed`** — when dispatch permanently fails back to draft (`schedule_failed:{messageId}:{scheduleVersion}`).
- **`outbound.usage_warning` / `outbound.usage_exhausted`** — after a successful usage reservation when message allowance crosses `OUTBOUND_NOTIFICATION_USAGE_WARNING_PERCENT` (default 80) or reaches 100% (`usage_warning:{userId}:{periodStart}`, `usage_exhausted:{userId}:{periodStart}`). Alerts do not reserve or commit additional usage; payload includes percentage only.

## Email delivery

Email delivery uses `SYSTEM_NOTIFICATION_MAILER` (falling back to `MAIL_MAILER`) and the system sender settings. It is a bounded three-attempt `notifications` queue job and does not create outbound messages, select a sender profile, consume outbound quota, invoke suppression, or emit another outbound notification. Database creation is committed before the job is queued. A mail failure leaves the in-app row intact.

## Idempotency and retention

Notification idempotency is enforced by a per-owner unique key. Lifecycle and provider-event hooks use stable message/event keys, so retries and duplicate provider callbacks do not create duplicate notifications. Schedule defer keys are throttled to one notification per message/reason/calendar day; usage alerts are throttled to one warning and one exhausted notification per user billing period start date.

Retention is configured with `OUTBOUND_NOTIFICATION_RETENTION_DAYS` (default 90); account deletion cascades rows. Operational counters use `outbound.metrics.notifications_*` cache keys and contain no PII.
