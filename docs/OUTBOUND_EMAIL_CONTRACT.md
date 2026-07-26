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
| `sender_profile_id` | Optional draft/profile link (nullable FK) |
| `reply_to_address` / `reply_to_name` | Optional Reply-To (must be owned inbox address) |
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
draft → scheduled → queued → sending → sent
draft → queued → sending → sent          (immediate submit)
                 sending → failed
                 failed → queued          (authorized retry)
                 queued → cancelled
                 scheduled → draft        (unschedule)
                 scheduled → cancelled    (cancel while scheduled)
                 sending → queued         (retryable failure with attempts remaining; Prompt 605)
```

| State | Meaning |
|---|---|
| `draft` | Persisted but not yet accepted for delivery; editable |
| `scheduled` | Fully validated, waiting for a future `scheduled_at` UTC time before queueing; not editable; **does not consume committed usage** until it transitions to `queued` |
| `queued` | Eligible for worker claim |
| `sending` | Atomically claimed by a delivery job |
| `sent` | Configured transport **accepted** the message |
| `delivered` | Provider delivery confirmation |
| `failed` | Permanent failure or retry exhaustion |
| `cancelled` | Cancelled while still `queued` or `scheduled` |

**`sent` ≠ delivered.** SMTP/provider acceptance only proves the transport accepted the payload.

Terminal states for stale-job protection: `sent`, `delivered`, `failed` (when exhausted), `cancelled`. Workers must claim with atomic `queued → sending` and must not overwrite terminal states. Provider events must not advance `scheduled` messages.

### Allowed transitions

| From | To | Condition |
|---|---|---|
| `draft` | `scheduled` | Validation passed; future local time resolved to UTC |
| `draft` | `queued` | Validation passed; job dispatched |
| `scheduled` | `scheduled` | Reschedule (optimistic `schedule_version`) |
| `scheduled` | `draft` | Unschedule or permanent dispatch failure |
| `scheduled` | `queued` | Due time reached (dispatcher) or send-now |
| `scheduled` | `cancelled` | User cancel |
| `queued` | `sending` | Atomic claim by delivery job |
| `sending` | `sent` | Transport result `accepted` |
| `sending` | `failed` | Permanent failure or attempts exhausted |
| `sending` | `queued` | Retryable failure with attempts remaining |
| `failed` | `queued` | Explicit authorized manual retry |
| `queued` | `cancelled` | Atomic cancel before claim |

### Scheduled sending API (Prompt 622)

All endpoints require `outbound_messages:write` and ownership-safe 404 for non-owners.

| Method | Path | Purpose |
|---|---|---|
| `POST` | `/api/v1/outbound-drafts/{draft}/schedule` | Schedule a complete draft (`version`, `local_date`, `local_time`, `timezone`) |
| `PATCH` | `/api/v1/outbound-messages/{message}/schedule` | Reschedule (`schedule_version`, local fields) |
| `DELETE` | `/api/v1/outbound-messages/{message}/schedule` | Unschedule → returns to `draft` |
| `POST` | `/api/v1/outbound-messages/{message}/send-now` | Queue immediately (`schedule_version`) |

Error codes: `schedule_time_invalid`, `schedule_timezone_invalid`, `schedule_conflict`, `message_not_schedulable`, `message_not_scheduled`, `schedule_already_dispatched`.

Optimistic concurrency: `version` (draft) / `schedule_version` (scheduled). The resource exposes `scheduled_at` (UTC ISO), `scheduled_timezone`, `scheduled_local_at`, and `can_reschedule` / `can_unschedule` / `can_send_now`. Internal claim/defer fields are never exposed.

**Usage policy:** scheduling and rescheduling do not reserve or commit outbound usage. Usage is reserved only when the message transitions to `queued` (send-now or dispatcher).

**Retention:** draft prune (`outbound:prune`) only targets `state = draft`. Scheduled messages are never pruned as drafts. Unscheduled drafts use `updated_at` for the draft retention window.

**Dispatcher:** `php artisan outbound:dispatch-scheduled` claims due scheduled messages, applies the same send authorization/rate limits as immediate submit, reserves usage, and dispatches `DeliverOutboundMessageJob` after commit. Temporary blocks (e.g. emergency stop) defer dispatch; permanent failures return the message to `draft`.

Audit actions: `outbound.schedule_created`, `outbound.schedule_updated`, `outbound.schedule_cancelled`, `outbound.schedule_dispatched`, `outbound.schedule_dispatch_deferred`, `outbound.schedule_dispatch_failed`.

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
- Deployment topology (isolated workers, timeout alignment, stale-sending
  reconciliation, per-queue heartbeats): see `docs/OUTBOUND_WORKER_DEPLOYMENT.md`
  (Prompt 613). The third workload `outbound_maintenance` (env
  `QUEUE_OUTBOUND_MAINTENANCE`, default `outbound-maintenance`) is reserved
  for domain-auth/suppression/abuse maintenance work, kept isolated from both
  delivery and provider events.

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
outbound.usage_reservation_released
outbound.usage_corrected
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

## Delivery / webhook support

- Persist provider message ids on transport acceptance for correlation.
- `POST /api/v1/webhooks/outbound/{provider}` accepts verified delivery events.
- Normalized event types: `accepted`, `delivered`, `temporary_failure`, `permanent_failure`, `bounced`, `complained`, `rejected`, `unknown`.
- State precedence: `sent → delivered`; permanent bounce/reject may mark `failed` if not already delivered; temporary failures never overwrite delivered; complaints are recorded even after delivery; cancelled never becomes delivered; unmatched events remain stored.
- Duplicate `(provider, provider_event_id)` is idempotent. Unsigned / unverified events are rejected (fail closed).
- `sent` ≠ `delivered`: only verified provider `delivered` events set `delivered_at`.
- Message correlation uses `provider` + normalized `provider_message_id` (angle-bracket variants accepted). Ambiguous matches do not mutate state.

### Generic HMAC provider

Headers: `X-Outbound-Timestamp`, `X-Outbound-Signature`.  
Canonical string: `{provider}.{timestamp}.{raw_body}` (HMAC-SHA256).  
Required secret: `OUTBOUND_GENERIC_DELIVERY_WEBHOOK_SECRET`.

### Amazon SES provider (Prompt 611)

First vendor-specific adapter. Selected because the project already stubs AWS/SES mailer names and SMTP 587/TLS matches SES SMTP relay.

| Item | Value |
|---|---|
| Provider key | `ses` |
| Webhook | `POST /api/v1/webhooks/outbound/ses` |
| Auth | Amazon SNS signature verification (RSA, SigningCertURL) |
| Content-Type | `text/plain` or `application/json` (SNS default is text/plain) |
| Correlation | Prefer `mail.commonHeaders.messageId`; fall back to `mail.messageId` |
| Transport identity | Set `OUTBOUND_PRIMARY_PROVIDER=ses` (or `OUTBOUND_PROVIDER=ses` for back-compat) so accepted SMTP messages store `provider=ses` |

**Environment**

| Env | Purpose | Default |
|---|---|---|
| `OUTBOUND_PROVIDER` | Back-compat alias/fallback for `OUTBOUND_PRIMARY_PROVIDER` | `generic` |
| `OUTBOUND_PRIMARY_PROVIDER` | `generic` or `ses` | falls back to `OUTBOUND_PROVIDER` |
| `OUTBOUND_SECONDARY_PROVIDER` | Optional second provider identity (see Prompt 619 below) | empty |
| `OUTBOUND_SES_SNS_TOPIC_ARN` | Optional TopicArn allowlist | empty |
| `OUTBOUND_SES_CERT_CACHE_TTL_SECONDS` | SNS signing cert cache TTL | `3600` |
| `OUTBOUND_SES_SUBSCRIPTION_CACHE_TTL_SECONDS` | Pending SubscribeURL cache | `3600` |
| `OUTBOUND_SES_WEBHOOK_MAX_BODY_BYTES` | Body limit for SES | `262144` |
| `OUTBOUND_SES_TRANSPORT_ALIASES` | Comma-separated legacy transport-driver names (e.g. `smtp`) whose messages should still correlate with SES webhooks (Prompt 619 migration opt-in) | empty (no aliases; provider-scoped only) |

**Signature verification**

1. Require SNS fields: `Type`, `MessageId`, `Timestamp`, `Signature`, `SignatureVersion` (`1` or `2`), `SigningCertURL`.
2. Timestamp skew enforced via `OUTBOUND_DELIVERY_WEBHOOK_TIMESTAMP_SKEW_SECONDS`.
3. Certificate URL must be HTTPS `sns.<region>.amazonaws.com` (or `.amazonaws.com.cn`), `.pem` path, no query/userinfo, no redirects, TLS verify enabled.
4. Certificates cached by URL hash with bounded TTL.
5. Constant-time success path via `openssl_verify`; fail closed on any malformation.

**Event mappings**

| SES / SNS | Normalized |
|---|---|
| `Send` | `accepted` |
| `Delivery` | `delivered` |
| `DeliveryDelay` / transient `Bounce` | `temporary_failure` |
| permanent `Bounce` | `bounced` |
| `Complaint` | `complained` |
| `Reject` / `Rendering Failure` | `rejected` |
| `Open` / `Click` / other | `unknown` |
| SNS `UnsubscribeConfirmation` | `unknown` (stored, no state change) |

**SNS subscription confirmation**

- Verified `SubscriptionConfirmation` payloads are **not** auto-confirmed (SSRF-safe).
- SubscribeURL must be HTTPS `sns.*.amazonaws.com`.
- Pending confirmation is cached; confirm explicitly:

```bash
php artisan outbound:confirm-ses-subscription --from-cache --dry-run
php artisan outbound:confirm-ses-subscription --from-cache
```

**Provider console setup**

1. Configure SES SMTP credentials into `OUTBOUND_SMTP_*`.
2. Create SNS topic; subscribe HTTPS endpoint `https://<app>/api/v1/webhooks/outbound/ses`.
3. Confirm subscription with the artisan command above.
4. Attach SES configuration set / identity notifications (bounce, complaint, delivery) to the topic.
5. Set `OUTBOUND_PROVIDER=ses` and optionally `OUTBOUND_SES_SNS_TOPIC_ARN`.

**Retries / duplicates**

SNS may redeliver the same `MessageId`; replay cache and unique `(provider, provider_event_id)` keep processing idempotent.

**Safe rollout**

1. Keep outbound feature flags disabled.
2. Deploy webhook + workers for `outbound-events`.
3. Confirm SNS subscription.
4. Send a single canary via SES SMTP with flags enabled for one domain.
5. Verify delivery/bounce events correlate without exposing certs, signatures, or raw payloads in logs/API.

**Test procedure (no live AWS calls)**

```bash
php artisan test --filter=SesOutboundProviderEvent
php artisan test --filter=ProviderEvent
php artisan test --filter=Webhook
```

## Domain authentication readiness (Prompt 612)

The system never modifies public DNS. Operators publish records; Temail verifies them.

| State | Meaning | Send allowed? |
|---|---|---|
| `unconfigured` | No provider DNS expectations / never ready | No (when enforce + expectations exist) |
| `pending` | Expected records not yet visible | No |
| `verified` | SPF + DKIM OK; DMARC `quarantine`/`reject` | Yes |
| `degraded` | SPF + DKIM OK; DMARC missing or `p=none` | Yes (default) |
| `failed` | Invalid/conflicting mandatory records | No |

Components tracked separately: `ownership`, `spf`, `dkim`, `dmarc`.

**SES expected records**

- SPF TXT at apex: `v=spf1 include:amazonses.com ~all` (exactly one SPF; `+all` rejected)
- DKIM: CNAME `{token}._domainkey.<domain>` → `{token}.dkim.amazonses.com` (tokens from `OUTBOUND_SES_DKIM_TOKENS`) or TXT `v=DKIM1 ... p=...`
- Ownership TXT: `temail-domain-verification=<hash>` (optional; also accepts existing `dns_verified_at`)
- DMARC TXT at `_dmarc.<domain>`: `v=DMARC1; p=quarantine` recommended

**Commands / schedule**

```bash
php artisan outbound:verify-domains
php artisan outbound:verify-domains --domain=example.com
```

Hourly scheduled with `withoutOverlapping`. Manual recheck: Filament **Operations → Domain Auth** (platform admin, rate-limited).

**Env**

| Env | Purpose |
|---|---|
| `OUTBOUND_DOMAIN_AUTH_ENFORCE` | Gate send path (default true) |
| `OUTBOUND_DOMAIN_AUTH_ALLOW_DEGRADED_DMARC` | Allow send when DMARC weak (default true) |
| `OUTBOUND_SES_DKIM_TOKENS` | Comma-separated Easy DKIM tokens |
| `OUTBOUND_SES_SPF_INCLUDE` | Expected SPF include mechanism |

When no SPF/DKIM expectations are configured (e.g. empty DKIM tokens + generic provider without SMTP host), enforcement does not block sending.

Audit: `outbound.domain_verification_started`, `outbound.domain_verified`, `outbound.domain_degraded`, `outbound.domain_verification_failed`.

## Subscription usage / entitlement metering (Prompt 618)

Beyond the boolean `send_email`/`reply_email`/`forward_email` gates above, outbound activity is metered against optional per-period plan limits: `outbound_messages_per_period`, `outbound_recipients_per_period`, `outbound_attachment_bytes_per_period`. A plan without one of these features attached is **unlimited** for that dimension (never a silent default) — see `docs/OUTBOUND_USAGE_ACCOUNTING.md` for the full design, reservation lifecycle, release policy, reconciliation command (`php artisan outbound:reconcile-usage`), and the user-visible `GET /api/v1/outbound-usage` endpoint (`outbound_messages:read`). This is entitlement/quota accounting only — no payment collection, invoicing, or metered billing export. Fully independent from the abuse controls (`OutboundRateLimiter`) documented below.

## Provider portability / secondary failover foundation (Prompt 619)

A secondary provider identity can now be configured (`OUTBOUND_SECONDARY_PROVIDER`) alongside the primary, with per-provider capability/readiness lookups (`OutboundProviderRegistry`), provider-scoped webhook correlation, and per-provider domain-authentication records. **Automatic cross-provider retry is not implemented anywhere** — duplicate-safety cannot be proven for every failure shape, so only an audited, platform-admin-only manual action (`RetryOutboundMessageWithProviderAction`) can resend a message through a different provider, and only for a narrow set of provably-safe pre-acceptance failures. See `docs/OUTBOUND_PROVIDER_PORTABILITY.md` for the full design, the eligibility rules, and the explicit list of what is and is not covered.

## Delivery attempts, timeline, and reconciliation (Prompt 614)

### Delivery attempts

Each transport submission appends an `outbound_delivery_attempts` row (unique on `outbound_message_id` + `attempt_number`). Rows store transport name, normalized result, safe failure category, provider message id, ambiguous flag, and timings — never body, BCC, raw SMTP responses, or credentials. Retries create new attempt rows; prior attempts are never overwritten.

### Message timeline

`GET /api/v1/outbound-messages/{id}/timeline` (`outbound_messages:read`) builds a read model from audit logs and provider events.

| Audience | Visible | Hidden |
|---|---|---|
| Owner (user) | created, queued, sending, retry_scheduled, sent, delivered, delayed, failed, cancelled, manual_retry | bounce diagnostics, complaint metadata, suppression source, BCC, raw provider ids/signatures, admin-only actions |
| Platform admin | above plus bounced/complained labels, safe failure categories, reconciliation flags | secrets, bodies, raw webhook payloads, attachment paths |

Filament: **Operations → Outbound Message Timeline** (admin).

### State precedence (enforced)

Illustrative order (higher wins; temporary failure never overwrites delivered; complaint after delivery stays visible and triggers suppression without undoing `delivered`):

```text
cancelled > delivered > permanent failure / bounced / rejected > sent > temporary failure / delayed > queued
```

Duplicate provider events are idempotent. Ambiguous transport acceptance is flagged for manual review — never auto-resent.

### Reconciliation

```bash
php artisan outbound:reconcile-events
```

Orchestrates unmatched provider-event retry, out-of-order repair, expired unmatched terminalization, missing-attempt backfill, and impossible-state detection. Bounded batches + locks; safe deterministic fixes only; otherwise `reconciliation_flagged_at` / `reconciliation_note`. Related: `outbound:reconcile-stale-sending`, `outbound:reconcile-unmatched-events` (see `docs/OUTBOUND_WORKER_DEPLOYMENT.md`).

Retention follows existing audit / provider-event retention policy; timeline entries are derived, not a second store of message bodies.

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
queue workers: outbound-delivery, outbound-events, outbound-maintenance (see docs/OUTBOUND_WORKER_DEPLOYMENT.md)
failed-job monitoring for those queues
stale-sending reconciliation: outbound:reconcile-stale-sending (scheduled every 5 minutes)
unmatched provider-event reconciliation: outbound:reconcile-unmatched-events (scheduled every 15 minutes)
webhook URL: POST /api/v1/webhooks/outbound/{provider}
OUTBOUND_PROVIDER=generic|ses
OUTBOUND_GENERIC_DELIVERY_WEBHOOK_SECRET (generic)
OUTBOUND_SES_SNS_TOPIC_ARN (optional SES allowlist)
php artisan outbound:confirm-ses-subscription --from-cache
retry: OUTBOUND_SEND_MAX_ATTEMPTS / BACKOFF
suppression admin review process
abuse thresholds / temp-block policy
operations access for platform admins
php artisan outbound:status --json
```

Optional sandbox SMTP proof: `RUN_OUTBOUND_SMTP_TESTS=1` with approved test recipient only.
# Outbound drafts

`outbound_messages` also stores private, owner-scoped drafts. Drafts begin in `draft`, use a monotonic `draft_version`, and may only transition to `queued`; deletion sets `draft_deleted_at` and never affects shared inbound attachments. Draft creation and edits do not reserve message usage.

Draft API routes are `/api/v1/outbound-drafts` (GET/POST), `/api/v1/outbound-drafts/{draft}` (GET/PATCH/DELETE), and `/api/v1/outbound-drafts/{draft}/submit` (POST). Read/write use the existing `outbound_messages` API scopes. PATCH and submit require `version`; stale writes return 409. Submission locks the draft, reruns sender/domain/entitlement/rollout, recipient, suppression, abuse, attachment, content, and usage checks, then atomically queues the same record and dispatches one job after commit. HTML is sanitized on every save. Reply and forward drafts require an owned source email; reply headers and recipient are derived server-side. Audit records contain only operation, version, and counts.

Authenticated web routes under `/outbound-drafts` provide the owner-only list, composer, edit, delete, and submit workflow and call the same domain service as the API. Lists omit bodies, HTML and BCC values. `OUTBOUND_DRAFT_RETENTION_DAYS` defaults to 30; `outbound:prune --confirm` redacts only stale, unheld draft content and recipients, clears attachment references, marks the draft deleted, preserves inbound source attachments and audits once. Repeated pruning is idempotent.

## Sender profiles (Prompt 623)

Per-inbox sender identity (display name, reply-to, signatures) via `outbound_sender_profiles`. From address always remains the owned inbox. See [OUTBOUND_SENDER_PROFILES.md](./OUTBOUND_SENDER_PROFILES.md).
# Status notifications

See [Outbound notifications](OUTBOUND_NOTIFICATIONS.md) for isolated system-mail status alerts. These alerts never enter the user outbound delivery pipeline.
