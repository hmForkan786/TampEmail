# Inbound Mail Operations Runbook

Operational guide for the signed webhook inbound pipeline. For architecture and audit results see `INBOUND_MAIL_PIPELINE_AUDIT.md`.

## Quick reference

| Command | Purpose |
| --- | --- |
| `php artisan inbound:health` | Pipeline health (exit 0 = healthy) |
| `php artisan queue:work --queue=default,attachment-scanning` | Process inbound + scans |
| `php artisan queue:failed` | Inspect failed jobs |
| `php artisan inbound:cleanup --dry-run` | Preview retention cleanup |
| `php artisan attachments:scanner-health` | Attachment scanner status |

## Pipeline flow

```text
POST /api/v1/inbound/webhook
  → HMAC + timestamp + rate limit
  → ProcessInboundMessageJob (default queue)
  → InboundMimeParser → InboundRecipientResolver
  → IngestInboundEmailAction
  → ScanInboundAttachmentJob (attachment-scanning)
  → User webhook: inbox.email.received
```

## Webhook failures

### Symptom: 401 invalid signature / timestamp

1. Verify `INBOUND_GENERIC_WEBHOOK_SECRET` matches provider configuration.
2. Confirm provider signs `provider.timestamp.raw_body` (exact bytes).
3. Check clock skew; default tolerance is 300 s (`INBOUND_WEBHOOK_TIMESTAMP_SKEW_SECONDS`).
4. Ensure `X-Inbound-Message-Id` is present.

### Symptom: 429 rate limit

- Key: `inbound:{provider}:{ip}`
- Adjust `INBOUND_WEBHOOK_RATE_LIMIT_PER_MINUTE` or investigate abusive source IP.

### Symptom: 413 payload too large

- Default max: 10 MiB (`INBOUND_WEBHOOK_MAX_BODY_BYTES`).
- Provider must truncate or use external blob reference (not implemented — full MIME in JSON).

### Symptom: 503 dispatch unavailable

- Queue connection down or dispatcher exception.
- Check `QUEUE_CONNECTION`, Redis/database availability, `failed_jobs` table.

## Provider resend / duplicate events

- Provider should reuse the same `X-Inbound-Message-Id` on retry.
- Duplicate webhooks may enqueue multiple jobs; ingest dedupes on `emails.message_id`.
- If duplicates appear in inbox, check whether provider sends **different** message IDs per retry (provider misconfiguration).

## Queue backlog

1. `php artisan inbound:health` — check `queue_backlog`.
2. Ensure workers run: `default` **and** `attachment-scanning`.
3. Scale workers; do not move `ProcessInboundMessageJob` to a queue no worker consumes.
4. Inspect slow parse/ingest via `email_processing_logs` and metrics.

## Worker failure / parse errors

1. `php artisan queue:failed` — find `ProcessInboundMessageJob` failures.
2. Common causes: malformed MIME, attachment limit exceeded, DB outage.
3. Job retries: 3 attempts, backoff 60 / 300 / 900 seconds.
4. After exhaustion, `failed()` records via `InboundFailureService` if email row exists.

**Note:** Full ingestion replay is **not** available — raw MIME is not retained at webhook. Fix root cause and ask provider to resend with same `X-Inbound-Message-Id`.

## Storage outage

- Ingest transaction rolls back; quarantine files deleted on failure.
- Provider retry with same message ID is safe after recovery.

## ClamAV / scanner outage

- Attachments remain `pending` or transition to `failed`.
- Downloads blocked until `clean` (unless scanner disabled → `skipped`).
- Admin replay: `ReplayInboundFailureAction` for scan-stage failures only (platform admin).
- `php artisan attachments:scanner-health`

## Unknown / expired recipient

- Job completes without email row; metric `rejected`.
- Not a queue failure — verify domain/inbox active state and `expires_at`.

## Admin replay

- **Scope:** attachment scan failures only (`ProcessingStage::Scan`).
- Requires active platform admin.
- Re-dispatches `ScanInboundAttachmentJob` after commit.
- Audited as `inbound.failure_replayed`.
- Does **not** bypass webhook signature or create arbitrary messages.

## Duplicate events investigation

1. Compare `X-Inbound-Message-Id` values for duplicate emails.
2. Query `emails.message_id` — should be unique.
3. Check `headers.mime_message_id` if provider changed MIME ID between retries (before fix, this caused duplicates).

## Incident evidence collection

Safe to collect:

- `inbound:health` JSON output
- `queue:failed` job UUIDs and exception class/message (redacted)
- `email_processing_logs` for affected email IDs
- `audit_logs` for `inbound.failure_replayed`
- Provider message ID, inbox ID, email ID, timestamps

Do **not** export:

- Webhook secrets
- Full MIME bodies from production logs
- Attachment binary content

## Escalation

| Severity | Condition | Action |
| --- | --- | --- |
| P1 | No inbound delivery, health unhealthy, backlog growing | Scale workers, fix queue/DB |
| P2 | Scan backlog, downloads blocked | Scanner health, admin rescan |
| P3 | Single message parse failure | Provider resend with same message ID |
