# Outbound Email Architecture Contract

Status: architecture contract (Prompt 601). Foundational enums, transport interface, and delivery DTOs exist. Live SMTP/provider submission, send/reply/forward APIs, and delivery webhooks are deferred to later prompts in this batch.

## Goals

- Allow authorized users to send, reply to, and forward email from owned temporary inboxes through a provider-independent queued pipeline.
- Preserve existing inbound `emails` behavior without schema or semantics breakage.
- Enforce fail-closed authorization, entitlement, attachment safety, idempotency, and sanitized observability.
- Distinguish provider **acceptance** (`sent`) from mailbox **delivery** (`delivered`); never claim delivery without verified provider evidence.

## Non-goals (initial batch)

- Bulk campaigns, mailing lists, or mail merge
- Arbitrary From addresses unrelated to the owned inbox
- Open/click tracking pixels
- Bounce/complaint webhook processing (documented as future)
- Public attachment URLs or new outbound file uploads outside forward-of-clean attachments
- Overloading inbound `MailServer` records as SMTP send credentials

## Audit findings (baseline)

| Question | Finding |
|---|---|
| Outgoing email model? | **None.** Platform is inbound-only today. |
| Share one table with inbound? | **No.** Keep `emails` inbound-only; add dedicated `outbound_messages`. |
| Conversation/thread relationships? | **None.** Inbound stores raw headers only; outbound will persist linkage fields (`source_email_id`, threading headers). |
| Sender authorization? | Inbox `full_address` is the receive identity; outbound From must be derived from owned inbox only. |
| Temporary-inbox-only send? | Yes for v1: send only from owned, active inboxes on outbound-enabled domains. |
| Reply recipients? | Server-derived from validated `Reply-To` else original `From`; client must not replace primary recipient. |
| Forward context? | User introduction + sanitized original context; clean attachments only via `AttachmentVisibilityPolicy::mayIncludeInOutgoing()`. |
| Plan entitlements? | New keys: `send_email`, `reply_email`, `forward_email` (+ numeric limits). |
| API scopes? | New: `outbound_messages:read`, `outbound_messages:write` (User-minimum). |
| Queued operations? | Transport submission (and retries) must be queued; request path only validates + persists + dispatches. |
| Delivery attempt storage? | On `outbound_messages`: attempt count, timestamps, sanitized failure code/message, provider name + provider message id. No credentials, no raw provider dumps. |
| Duplicate prevention? | Client idempotency key + unique `(user_id, idempotency_key)` + atomic state claims. |
| Abuse / limits? | Entitlement limits + config caps (recipients, body size, rate) + API-key throttle. |
| Content sanitization? | Reuse Symfony HtmlSanitizer pattern (`InboundHtmlSanitizer` style); header-injection stripping; length caps. |

## Message model choice

**Decision: dedicated `outbound_messages` model/table.**

Rationale:

1. Existing `emails` table and docs treat records as inbound disposable-inbox metadata; adding direction/delivery fields risks polluting inbound queries, retention, and API resources.
2. Outbound needs distinct lifecycle, idempotency, provider metadata, and operation type (`send` / `reply` / `forward`) that do not map cleanly onto inbound processing statuses.
3. Smallest design that preserves inbound behavior: new table + optional `source_email_id` FK to inbound `emails` for reply/forward.

Unified polymorphic message subtypes are deferred; they add complexity without benefit while inbound and outbound APIs remain separate.

### Core fields (schema introduced in Prompt 602+)

| Field | Purpose |
|---|---|
| `id` (UUID) | Project-standard identifier |
| `user_id` | Owner / actor |
| `inbox_id` | Sending inbox (From identity source) |
| `source_email_id` | Nullable inbound parent for reply/forward |
| `operation` | `send` \| `reply` \| `forward` |
| `state` | Lifecycle state |
| `idempotency_key` | Client key; unique per user |
| `from_address` / `from_display_name` | Derived sender |
| `to` / `cc` / `bcc` | Normalized JSON recipient lists |
| `subject`, `text_body`, `html_body` | Sanitized content |
| `in_reply_to`, `references` | Threading headers (reply/forward) |
| `provider`, `provider_message_id` | Safe transport metadata |
| `attempt_count` | Submission attempts |
| `queued_at`, `sending_at`, `sent_at`, `failed_at`, `cancelled_at` | Timestamps |
| `failure_code`, `failure_message` | Sanitized failure only |
| `metadata` | Non-secret operational JSON |

Provider credentials must never be stored on the message row.

## Lifecycle states

```text
draft → queued → sending → sent
                 sending → failed
                 failed → queued          (authorized retry)
                 queued → cancelled
                 sending → queued         (retryable failure with attempts remaining; Prompt 605)
```

| State | Meaning |
|---|---|
| `draft` | Persisted but not yet accepted for delivery (optional; may be skipped if create+queue is atomic) |
| `queued` | Eligible for worker claim |
| `sending` | Atomically claimed by a delivery job |
| `sent` | Configured transport **accepted** the message |
| `delivered` | Reserved for future provider delivery confirmation; **not set** in the initial batch |
| `failed` | Permanent failure or retry exhaustion |
| `cancelled` | Cancelled while still `queued` |

**`sent` ≠ delivered.** SMTP/provider acceptance only proves the transport accepted the payload.

Terminal states for stale-job protection: `sent`, `delivered`, `failed` (when exhausted), `cancelled`. Workers must claim with atomic `queued → sending` and must not overwrite terminal states.

### Allowed transitions

| From | To | Condition |
|---|---|---|
| `draft` | `queued` | Validation passed; job dispatched |
| `queued` | `sending` | Atomic claim by delivery job |
| `sending` | `sent` | Transport result `accepted` |
| `sending` | `failed` | Permanent failure or attempts exhausted |
| `sending` | `queued` | Retryable failure with attempts remaining |
| `failed` | `queued` | Explicit authorized manual retry |
| `queued` | `cancelled` | Atomic cancel before claim |

## Operations

### `send`

- New outbound composition from an owned inbox.
- Recipients supplied by client (`to` required; `cc`/`bcc` optional).
- No `source_email_id`.
- Feature key: `send_email`.

### `reply`

- Requires inbound `source_email_id` owned via the same inbox.
- Primary recipient derived server-side: validated `Reply-To` if present and safe, else original sender.
- Client must not replace the primary recipient; optional `cc` only if explicitly enabled by config (default: allowed within recipient caps).
- Generate `In-Reply-To` / `References` from stored message IDs; never accept raw threading headers from the client.
- Subject: preserve existing `Re:` prefix; add once when absent.
- Feature key: `reply_email`.

### `forward`

- Requires inbound `source_email_id` owned via the same inbox.
- Recipients client-selected (same validation as send).
- Body: user introduction + sanitized original context (no BCC, no storage paths, no scanner metadata, no quarantine links).
- Attachments: all-or-nothing; only `mayIncludeInOutgoing()` attachments; re-check at claim time.
- Subject: preserve `Fwd:`/`Fw:`; otherwise add normalized `Fwd:`.
- Feature key: `forward_email`.

Reply and forward are **not** aliases of send: eligibility, recipient derivation, threading, and attachment rules differ.

## Authorization

All of the following must pass (fail closed):

1. Authenticated API-key (or future session) principal maps to an active `User` (`status = active`; suspended/banned/pending denied).
2. Inbox belongs to the user (`inbox.user_id`).
3. Inbox is active and not expired for outbound use.
4. Domain is active; outbound enabled via domain flag or config allowlist (`domains.outbound_enabled` / metadata / `config('outbound.domains_require_outbound_flag')` — default require explicit enablement once the column exists; until then use `config('outbound.enabled')` plus domain `is_active`).
5. Feature flag / config for the operation is enabled (`outbound.send_enabled`, etc.).
6. Plan entitlement for the operation feature key.
7. Applicable rate/volume/recipient/body/attachment limits not exceeded.
8. Sender address is always `inbox.full_address` (optional validated display name); arbitrary `from` rejected.
9. Attachments (forward only) pass `AttachmentVisibilityPolicy::mayIncludeInOutgoing()`.
10. Platform admins still follow safe sender and transport rules unless an existing explicit policy grants a documented exception (none today → same rules).

Disabled domain or suspended user ⇒ deny before persistence.

## Entitlement model

| Feature key | Controls |
|---|---|
| `send_email` | New outbound send |
| `reply_email` | Reply to inbound |
| `forward_email` | Forward inbound |

Numeric limits (pivot `feature_value` or config defaults):

- Messages per hour / day (per operation and/or aggregate)
- Recipients per message
- Body size (text + HTML)
- Queued outbound messages
- Attachments per forward / total attachment bytes
- Provider submission attempts (aligned with retry config)

UI checks are never sufficient; Actions enforce entitlements server-side via `EntitlementService`.

## API scopes

| Scope | Min role | Use |
|---|---|---|
| `outbound_messages:read` | User | Status / show |
| `outbound_messages:write` | User | Create send / reply / forward, cancel, manual retry |

Reply/forward use `outbound_messages:write` (not separate `emails:reply`) unless a later scope split is approved. Documented choice: **shared write scope** for all outbound mutations.

## Idempotency

1. Client supplies `Idempotency-Key` header or body field (required for create endpoints).
2. Unique database constraint on `(user_id, idempotency_key)`.
3. Same user + key + same request fingerprint ⇒ return existing message (replay).
4. Same user + key + different fingerprint ⇒ `409 Conflict`.
5. Queue retries serialize only message UUID; they never insert a second row.
6. Atomic `queued → sending` claim prevents double transport submit; store `provider_message_id` when returned to aid future dedupe.
7. Optional deterministic fingerprint: hash of normalized recipients + subject + bodies + operation + source_email_id + attachment ids.

## Queue design

- Workload name: `outbound_delivery` (env `QUEUE_OUTBOUND_DELIVERY`, default queue `outbound-delivery`).
- Job payload: outbound message id only.
- Flow: claim → reload authz + content → (forward) re-check attachments → transport `send()` → persist safe result → audit.
- Retryable vs permanent classification drives re-queue vs `failed` (Prompt 605).
- Workers must not log bodies, BCC lists, or credentials.

## Attachment safety

Reuse `AttachmentVisibilityPolicy::mayIncludeInOutgoing()`:

```text
scan_status = clean
AND is_safe = true
AND private disk
AND storage object exists
```

Reject: `pending`, `scanning`, `infected`, `failed`, `skipped`, deleted, missing object, cross-email IDs, oversize. Prefer all-or-nothing at request time; re-validate at send claim (TOCTOU). Do not serialize file bytes in queue payloads; stream from private storage at assembly time.

## Transport abstraction

```php
interface OutboundTransportInterface
{
    public function send(OutboundMessageData $message): OutboundDeliveryResult;
}
```

Results distinguish:

| Result | Meaning | Retry? |
|---|---|---|
| `accepted` | Provider accepted message | No (success → `sent`) |
| `rejected` | Permanent policy/content rejection | No |
| `temporary_failure` | Transient transport/provider issue | Yes, while attempts remain |
| `permanent_failure` | Non-retryable transport failure | No |
| `configuration_failure` | Missing/invalid SMTP or mailer config | No |

Initial provider strategy:

- Prefer explicitly configured Laravel mailer / SMTP adapter selected by `OUTBOUND_TRANSPORT` (`smtp`, `mail`, `array` for tests, `unavailable` default).
- Production missing/invalid configuration ⇒ bind fail-closed `UnavailableOutboundTransport` (permanent failure). Never silently fall back to local `log`/`sendmail` in production.
- Platform notification mail (`config/mail.php` default) remains separate from inbox outbound.

## Retry classification (Prompt 605)

Defaults:

```text
OUTBOUND_SEND_MAX_ATTEMPTS=3
OUTBOUND_SEND_BACKOFF_SECONDS=60,300,900
```

Retryable: timeouts, temporary DNS, SMTP 4xx, rate limits, connection reset, temporary TLS failures.  
Non-retryable: invalid recipient, SMTP 5xx, unauthorized sender, bad credentials, disabled domain, unsafe attachment, cancelled, invalid construction.

## Audit events

Safe metadata only (ids, operation, state, provider name, recipient **count**, attempt, sanitized failure code, timestamps):

```text
outbound.message_created
outbound.message_queued
outbound.message_sending
outbound.message_sent
outbound.message_failed
outbound.message_cancelled
outbound.reply_created / outbound.reply_queued / outbound.reply_sent / outbound.reply_failed
outbound.forward_created / outbound.forward_queued / outbound.forward_sent / outbound.forward_failed
outbound.retry_scheduled
outbound.retry_exhausted
outbound.manual_retry_requested
```

Never audit: passwords, API tokens, raw SMTP responses, full bodies, BCC addresses, storage paths, attachment bytes.

## Recipient suppression

- Permanent bounce, complaint, and explicit invalid-recipient provider outcomes create global suppressions (hashed lookup + encrypted reversible value for admin display).
- Temporary failures do not suppress.
- Send/reply/forward validate recipients before queueing; delivery jobs re-check immediately before transport.
- Ordinary users receive a safe `recipient_suppressed` error without provider/source details.
- Platform admins manage suppressions under **Operations → Recipient Suppressions**; complaint/provider removals require elevated authorization.
- Ops metrics expose active/bounce/complaint/manual counts and blocked-send volume; readiness does not fail merely because suppressions exist.

## Abuse controls

- Shared `OutboundRateLimiter` for API/UI create paths with DB row-lock reservation.
- Limits: messages/minute/hour/day, unique recipients/hour/day, concurrent queued, outbound bytes/day.
- Counting: new accepted creates consume quota; idempotent replay does not; queue retries do not create new rows; validation failures do not consume quota; `to`/`cc`/`bcc` count after dedupe.
- Temporary blocks / suspensions via `outbound_abuse_blocks` with audit trail; safe 429/403 without internal thresholds.
- Ops metrics: throttled requests, blocked/suspended users, bounce/complaint spikes.

## Privacy and logging

- BCC visible only to the authorized sender context; never in shared logs or non-owner responses.
- Application logs: message id, state, failure code — not bodies.
- Secrets: SMTP credentials via env / encrypted config only; never plaintext application tables for outbound transport secrets in this batch.

## Domain outbound enablement

Until a dedicated `domains.outbound_enabled` column ships, outbound requires:

1. Global `outbound.enabled = true`
2. Per-operation flags (`send_enabled`, `reply_enabled`, `forward_enabled`)
3. Domain `is_active = true`

A domain column may be added when send foundation lands if needed for per-domain control; default new domains outbound-disabled.

## Future delivery / webhook support

- Persist provider message ids on transport acceptance for correlation.
- `POST /api/v1/webhooks/outbound/{provider}` accepts HMAC-signed delivery events (`X-Outbound-Timestamp`, `X-Outbound-Signature`).
- Normalized event types: `accepted`, `delivered`, `temporary_failure`, `permanent_failure`, `bounced`, `complained`, `rejected`, `unknown`.
- State precedence: `sent → delivered`; permanent bounce/reject may mark `failed` if not already delivered; temporary failures never overwrite delivered; complaints are recorded even after delivery; cancelled never becomes delivered; unmatched events remain stored.
- Duplicate `(provider, provider_event_id)` is idempotent. Unsigned events are rejected in production (missing secret fails closed).
- `sent` ≠ `delivered`: only verified provider `delivered` events set `delivered_at`.

Required secret: `OUTBOUND_GENERIC_DELIVERY_WEBHOOK_SECRET` (and future per-provider secrets under `outbound.delivery_webhook.providers`).

## Foundational code (Prompt 601)

| Artifact | Role |
|---|---|
| `OutboundMessageState` | Lifecycle enum + transition helpers |
| `OutboundOperation` | `send` / `reply` / `forward` |
| `OutboundTransportResult` | Transport outcome enum |
| `OutboundTransportInterface` | Provider-independent send contract |
| `OutboundMessageData` | Transport input DTO |
| `OutboundDeliveryResult` | Transport result DTO |
| `UnavailableOutboundTransport` | Fail-closed default binding |
| `config/outbound.php` | Feature flags, limits, retry defaults |

## Implementation roadmap

| Prompt | Scope |
|---|---|
| 601 | This contract + foundational abstractions |
| 602 | Schema, send API, queue job, real/configured transport adapter |
| 603 | Reply workflow |
| 604 | Forward + clean attachments |
| 605 | Retries, status API, ops monitoring, cancel/manual retry |

## Operational troubleshooting (stub)

- Queue workers must consume `outbound-delivery`.
- Invalid transport config ⇒ readiness `failed`; messages move to `failed` with sanitized `invalid_config` / `transport_unavailable`.
- Distinguish user-visible failure categories from internal exception detail.
- Full ops metrics and Filament page: Prompt 605.

## Transport configuration

| Env | Purpose | Default |
|---|---|---|
| `OUTBOUND_ENABLED` | Global kill switch | `false` |
| `OUTBOUND_SEND_ENABLED` / `OUTBOUND_REPLY_ENABLED` / `OUTBOUND_FORWARD_ENABLED` | Per-operation flags | `false` |
| `OUTBOUND_TRANSPORT` | `unavailable`, `array`, `smtp`, `mail` | `unavailable` |
| `OUTBOUND_MAILER` | Dedicated Laravel mailer name | `outbound` |
| `OUTBOUND_SMTP_HOST` | Dedicated SMTP host (required for smtp) | empty |
| `OUTBOUND_SMTP_PORT` | SMTP port | `587` |
| `OUTBOUND_SMTP_USERNAME` / `OUTBOUND_SMTP_PASSWORD` | SMTP auth | empty |
| `OUTBOUND_SMTP_ENCRYPTION` | `tls`, `ssl`, `starttls`, `none` | `tls` |
| `OUTBOUND_SMTP_TIMEOUT` | Seconds | `30` |
| `OUTBOUND_SMTP_LOCAL_DOMAIN` | EHLO / Message-ID domain | APP_URL host |
| `OUTBOUND_SMTP_VERIFY_PEER` | TLS peer verification | `true` |
| `OUTBOUND_SMTP_REQUIRE_AUTH` | Require username+password | `true` |
| `OUTBOUND_SEND_MAX_ATTEMPTS` | Bounded delivery attempts | `3` |
| `OUTBOUND_SEND_BACKOFF_SECONDS` | Comma-separated backoff | `60,300,900` |

Missing or unknown transport fails closed. Never silently use local `log`/`sendmail` in production. Platform `MAIL_*` remains separate from inbox outbound (`OUTBOUND_SMTP_*` + mailer `outbound`).

Transport results: `accepted`, `rejected`, `temporary_failure`, `permanent_failure`, `configuration_failure`.

Readiness validates transport selection, mailer existence, host/port/encryption/credentials without sending mail.

## Status meanings

| State | Meaning |
|---|---|
| `queued` | Waiting for worker claim |
| `sending` | Atomically claimed |
| `sent` | Provider **accepted** the message (not mailbox delivery) |
| `failed` | Permanent failure or retry exhaustion |
| `cancelled` | Cancelled while queued |
| `delivered` | Reserved; not set without provider delivery evidence |

## Cancellation and manual retry

- Cancel only while `queued` via `POST /api/v1/outbound-messages/{id}/cancel` (`outbound_messages:write`).
- Manual retry only for `failed` when the failure category permits retry, entitlements remain valid, and forward attachments still pass safety checks: `POST /api/v1/outbound-messages/{id}/retry`.
- Audit: `outbound.message_cancelled`, `outbound.manual_retry_requested`, `outbound.retry_scheduled`, `outbound.retry_exhausted`.

## Operations monitoring

Admin Filament page: **Operations → Outbound Email**. Page load never sends external email.

```bash
php artisan outbound:status --json
```

Readiness states: `healthy`, `degraded`, `failed`, `unknown` (feature disabled).

## Deployment checklist

```text
OUTBOUND_TRANSPORT=smtp
OUTBOUND_MAILER=outbound
OUTBOUND_SMTP_HOST / PORT / ENCRYPTION / USERNAME / PASSWORD
OUTBOUND_SMTP_VERIFY_PEER=true
OUTBOUND_SMTP_TIMEOUT (workers must exceed this)
domains.outbound_enabled for sending domains
OUTBOUND_ENABLED + send/reply/forward flags
plan entitlements: send_email, reply_email, forward_email
queue workers: outbound-delivery, outbound-events
failed-job monitoring for those queues
webhook URL: POST /api/v1/webhooks/outbound/{provider}
OUTBOUND_GENERIC_DELIVERY_WEBHOOK_SECRET
retry: OUTBOUND_SEND_MAX_ATTEMPTS / BACKOFF
suppression admin review process
abuse thresholds / temp-block policy
operations access for platform admins
php artisan outbound:status --json
```

Optional sandbox SMTP proof: `RUN_OUTBOUND_SMTP_TESTS=1` with approved test recipient only.
