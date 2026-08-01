# Inbound Mail Pipeline Audit (Prompt 653)

Production-readiness audit of the signed webhook → inbox visibility pipeline. Architecture boundaries are unchanged: **no native SMTP/MX/LMTP ingress**.

**Audit date:** 2026-07-29  
**Branch:** `feature/billing-payments`  
**Final decision:** **PASS** (after corrective fix for broken MIME parser and provider-id dedup authority)

## Frozen architecture (verified unchanged)

```text
External inbound provider
        ↓
POST /api/v1/inbound/webhook
        ↓
Signature / timestamp verification
        ↓
Inbound event persistence (metrics only at webhook; no email row)
        ↓
Queued processing (ProcessInboundMessageJob)
        ↓
Inbox resolution (InboundRecipientResolver)
        ↓
Email + body + attachment storage (IngestInboundEmailAction)
        ↓
User-visible inbox (API / Filament)
```

## Authoritative services

| Stage | Service / class | File |
| --- | --- | --- |
| Webhook boundary | `InboundWebhookController` | `app/Http/Controllers/Api/V1/InboundWebhookController.php` |
| Queue dispatch | `QueuedInboundWebhookDispatcher` | `app/Services/Inbound/QueuedInboundWebhookDispatcher.php` |
| Async processing | `ProcessInboundMessageJob` | `app/Jobs/ProcessInboundMessageJob.php` |
| MIME parsing | `InboundMimeParser` | `app/Services/Inbound/InboundMimeParser.php` |
| Recipient routing | `InboundRecipientResolver` | `app/Services/Inbound/InboundRecipientResolver.php` |
| Persistence | `IngestInboundEmailAction` | `app/Actions/Inbound/IngestInboundEmailAction.php` |
| HTML safety | `InboundHtmlSanitizer` | `app/Services/Inbound/InboundHtmlSanitizer.php` |
| Attachment scan | `ScanInboundAttachmentJob` / `AttachmentScanService` | `app/Jobs/ScanInboundAttachmentJob.php` |
| Download gate | `AttachmentVisibilityPolicy` | `app/Policies/AttachmentVisibilityPolicy.php` |
| User webhooks | `WebhookDispatchService` | `app/Services/Webhook/WebhookDispatchService.php` |
| Admin replay | `ReplayInboundFailureAction` | `app/Actions/Inbound/ReplayInboundFailureAction.php` |
| Health | `inbound:health` | `app/Console/Commands/InboundHealth.php` |

Contract reference: `docs/INBOUND_ROUTING_CONTRACT.md`

## Audit results by area

### 1. Inbound endpoint — PASS

| Check | Result |
| --- | --- |
| Route | `POST /api/v1/inbound/webhook` (`routes/api.php`) |
| Middleware | Outside `api.key` / entitlement; auth is HMAC at controller |
| Content types | `application/json` required when `Content-Type` present |
| Raw body preservation | `$request->getContent()` used for HMAC |
| Malformed / missing headers | Rejected with 400/401/422 |
| Payload size | `inbound.max_body_bytes` (default 10 MiB) → 413 |
| Rate limiting | Per provider + IP (`inbound.rate_limit_per_minute`) |
| Safe errors | No secrets or raw MIME in JSON responses |
| HTTPS | Deployment requirement (see deployment checklist) |

### 2. Signature and timestamp — PASS

- HMAC-SHA256 over `provider.timestamp.raw_body`
- `hash_equals` constant-time comparison
- Timestamp skew: `inbound.timestamp_skew_seconds` (default 300)
- Secret from env only: `INBOUND_GENERIC_WEBHOOK_SECRET`
- Empty secret fails closed in production
- No dev bypass in controller

### 3. Replay protection — PARTIAL (documented)

| Layer | Mechanism |
| --- | --- |
| Webhook | Timestamp window + HMAC + rate limit |
| Nonce table | **Not implemented** at inbound webhook (unlike billing) |
| Post-accept | Provider `X-Inbound-Message-Id` → `emails.message_id` UNIQUE dedup at ingest |

**Operational note:** Duplicate signed webhooks within the timestamp window enqueue duplicate jobs but cannot create duplicate emails. This is acceptable for production when provider retries are idempotent on `X-Inbound-Message-Id`.

### 4. Payload validation — PASS

- Required: `recipient` (non-empty string)
- Optional: `sender`, `raw_mime_payload`, `received_at`
- Invalid JSON / missing recipient → 422
- Attachment limits enforced at ingest (`config/attachments.php`)
- Unknown JSON fields ignored

### 5. Inbox resolution — PASS

- IDN domain normalization, local-part case preserved
- Exact `inboxes.full_address` lookup
- Active domain/inbox, expiry, public ingress policy
- Unknown recipient: job exits without persistence (metric `rejected`)
- Owner isolation: email stored only on resolved `inbox_id`

### 6. Deduplication and idempotency — PASS (after fix)

**Canonical deduplication authority:**

```text
Primary:  X-Inbound-Message-Id  →  emails.message_id (UNIQUE)
Evidence: MIME Message-ID stored in headers.mime_message_id when different
```

**Defect found and fixed:** Parser previously preferred MIME `Message-ID` over provider ID; provider retries with new MIME IDs could duplicate emails. `InboundMimeParser` now uses provider ID as canonical `messageId`.

### 7. Transaction boundary — PASS

`IngestInboundEmailAction` wraps email + body + attachments + events in one DB transaction. Attachment scan jobs and user webhooks dispatch **after** commit. Rollback deletes quarantine files on failure.

### 8. Queue processing — PASS (intentional topology)

| Job | Queue | Tries | Backoff |
| --- | --- | --- | --- |
| `ProcessInboundMessageJob` | **default** | 3 | 60, 300, 900 s |
| `ScanInboundAttachmentJob` | `attachment-scanning` | unique per attachment | configured |

**Prompt 653 §8 decision:** Keeping `ProcessInboundMessageJob` on the default queue is **intentionally documented and operationally safe**. Named `mail-ingestion` queues exist in config but are reserved; moving the job would be naming-only unless worker topology proves starvation. Deploy workers that consume `default` (or remap default) plus `attachment-scanning`. Outbound queues remain isolated.

### 9. Parsing — PASS (after fix)

**Defect found and fixed:** `InboundMimeParser` called non-existent `Symfony\Component\Mime\Email::fromString()`, breaking production parsing. Replaced with `zbateson/mail-mime-parser` v3.

Supports: text/html, multipart, encoded headers, attachments, UTF-8. Parser exceptions fail the job (retries, then `failed()` hook).

### 10. Header safety — PASS

- Envelope recipient from verified webhook payload, not From/To headers
- Normalized header storage (truncated)
- SPF/DKIM/DMARC not fabricated from user content

### 11. Body storage — PASS

- `email_bodies`: sanitized HTML + raw text in database
- Transactionally linked to `emails`
- Size from envelope `contentLength` on email row

### 12. HTML sanitization — PASS

- Symfony `HtmlSanitizer` with `allowSafeElements()` at ingest
- API output path re-sanitizes where applicable
- Scripts/event handlers stripped before storage

### 13. Plain-text rendering — PASS

- Text stored separately; HTML views use sanitized HTML or escaped text paths in API resources

### 14–15. Attachments and scanning — PASS

- Private `attachments` disk, quarantine path
- Pending until scan clean (or skipped when scanner disabled)
- `AttachmentVisibilityPolicy`: download only when `clean` + `is_safe`
- Filename basename normalization, size/count limits

### 16. State machine — PASS (documented as-is)

**`ProcessingStatus` on email:** ingest sets `stored` directly.

**`EmailEventType`:** ingest writes `received`. Other event types exist in enum but are not all populated on inbound path.

**Operational stages:** `EmailProcessingLog` + `ProcessingStage` / `ProcessingLogStatus` via metrics and scan jobs.

### 17. Inbox visibility — PASS

- Email list/detail authorized by inbox owner
- Incomplete scan: attachment download blocked; email visible with pending attachments
- Expired inbox: resolver rejects new delivery

### 18. Notifications — PASS

- `WebhookDispatchService` for `inbox.email.received` after ingest commit
- `WebhookDelivery` idempotent per endpoint + email id
- `DeliverWebhookJob::afterCommit()`

### 19. Commercial integration — PASS (documented policy)

- **No inbound message metering** at webhook or ingest
- Entitlement enforced on inbox **read** API, not delivery
- Inbox expiry is the primary inbound commercial gate
- Provider retry does not double-count usage (no ingest counter)

### 20. Failure recovery — PASS

- Job retries with backoff
- `InboundFailureService` for terminal scan failures
- Partial file cleanup on ingest rollback
- Duplicate provider callback: idempotent on `message_id`

### 21. Admin replay — PASS (limited scope)

- Platform admin only
- **Attachment scan failures only** — ingestion replay blocked (no retained raw MIME)
- Uses `RescanFailedAttachmentAction` + audit log

### 22. Retention — PASS (feature-flagged)

- `inbound:cleanup` when `inbound_retention.cleanup_enabled=true`
- Replay keys: N/A (no inbound nonce table)
- Webhook payloads not persisted as rows

### 23. Observability — PASS

- `InboundMetricsRecorder`: received, queued, throttled, rejected, duplicate, persisted, failed
- Correlation: provider message id, email id, stage
- No secrets or full bodies in metrics

### 24. Health — PASS

- `php artisan inbound:health` — failure rate, backlog, pending scan age, retry exhaustion

### 25. Performance and abuse — PASS

- Synchronous webhook work minimized (validate + queue)
- Rate limit + body cap
- Async parse/ingest

### 26. Security — PASS

- Signed boundary, owner isolation, XSS via sanitizer, path traversal on attachment filenames
- **No remote URL fetching** in inbound processing (SSRF N/A)

## Defects found and corrections

| ID | Severity | Issue | Correction |
| --- | --- | --- | --- |
| D-653-1 | **BLOCKER** | `Email::fromString()` does not exist; parsing always failed in workers | Rewrote `InboundMimeParser` with `zbateson/mail-mime-parser` |
| D-653-2 | **BLOCKER** | Dedup used MIME Message-ID when present, not provider ID | Provider `X-Inbound-Message-Id` is canonical `messageId`; MIME ID stored as evidence |

## Known limitations

1. No inbound webhook nonce/replay table — reliance on timestamp + HMAC + ingest dedup
2. `ProcessInboundMessageJob` on default queue — workers must consume it
3. Ingestion admin replay unavailable without raw MIME retention
4. Inbound delivery not gated by subscription quota (read API gated)
5. ClamAV deep certification deferred to Prompt 656; integration boundary verified here
6. Native SMTP/MX deferred by design

## Regression summary

See completion report for exact command output. Inbound-focused tests pass after fixes, including new `InboundPipelineIntegrityTest`.

## Final decision

| Criterion | Status |
| --- | --- |
| Signed webhook boundary | PASS |
| Replay / dedup safe for duplicate email | PASS |
| Payload validation fail-closed | PASS |
| Owner-safe resolution | PASS |
| Queue retry-safe | PASS |
| Parser functional | PASS (fixed) |
| Body/HTML/attachment safety | PASS |
| Documentation complete | PASS |

**Prompt 653 status: PASS**
