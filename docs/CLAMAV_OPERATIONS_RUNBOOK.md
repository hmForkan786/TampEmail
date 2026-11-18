# ClamAV Operations Runbook

Operational guide for attachment malware scanning with ClamAV (`clamd`). Architecture and audit: `ATTACHMENT_SECURITY_AUDIT.md`. Contract: `ATTACHMENT_SCANNING_CONTRACT.md`. Integration fixtures: `CLAMAV_INTEGRATION_TESTING.md`.

## Quick reference

| Command | Purpose |
| --- | --- |
| `php artisan attachments:scanner-health` | Lightweight PING / config readiness |
| `php artisan attachments:scanner-live-check` | Explicit clean + EICAR content probes |
| `php artisan attachments:scanner-status` | Counts, queue health, quarantine overview |
| `php artisan queue:work --queue=attachment-scanning` | Process scan jobs |
| `php artisan inbound:cleanup` | Retention (skips pending/scanning) |

Add `--json` where supported for monitoring hooks.

## Pipeline

```text
Inbound ingest → store private quarantine path → pending
  → ScanInboundAttachmentJob (attachment-scanning)
  → ClamAV INSTREAM
  → clean | infected | failed (retry then terminal)
```

Disabled backend (`ATTACHMENT_SCANNER_BACKEND=disabled`): `pending` → `skipped`; downloads remain blocked.

## Enablement (fail-closed)

```env
ATTACHMENT_SCANNER_BACKEND=disabled   # default
ATTACHMENT_CLAMAV_HOST=127.0.0.1
ATTACHMENT_CLAMAV_PORT=3310
# or ATTACHMENT_CLAMAV_SOCKET=/var/run/clamav/clamd.ctl
ATTACHMENT_SCAN_TIMEOUT_SECONDS=30
ATTACHMENT_SCAN_MAX_ATTEMPTS=3
ATTACHMENT_SCAN_BACKOFF_SECONDS=60,300,900
QUEUE_ATTACHMENT_SCANNING=attachment-scanning
```

Enable only after:

1. `clamd` reachable on private host/socket  
2. `attachments:scanner-health` healthy  
3. Optional live-check green  
4. Workers consuming `attachment-scanning`  
5. Set `ATTACHMENT_SCANNER_BACKEND=clamav`

Never treat unavailable ClamAV as clean.

## Common failures

### Symptom: All new attachments `skipped`

- Backend still `disabled`
- Downloads correctly return 404 until ClamAV is enabled and scans complete

### Symptom: Attachments stuck `pending` / backlog

```bash
php artisan attachments:scanner-status --json
php artisan queue:work --queue=attachment-scanning
```

- Worker not running or wrong queue name
- Job uniqueness (`uniqueFor=3600`) — check for stuck reserved jobs

### Symptom: Terminal `failed` with `retry_exhausted` / unavailable

1. `attachments:scanner-health` — daemon down, wrong host/port, firewall  
2. Timeouts — raise carefully; keep SMTP/job ordering unrelated  
3. Admin rescan failed rows only via Filament (audited); cannot force clean

### Symptom: Infected surge

- Review Filament **Quarantined Attachments**
- Confirm EICAR/live-check not mis-scheduled continuously
- Purge only with admin action; holds block purge

### Symptom: Downloads 404 for “clean looking” mail

- Confirm `scan_status=clean` **and** `is_safe=true`
- File must exist on private disk
- Inbox must be owned, active, not expired
- Unsafe path / wrong disk → 404

## Health vs live-check

| Probe | When to use |
| --- | --- |
| Health (PING) | Frequent monitoring / page load |
| Live-check | Explicit operator verification only; rate-limited in UI |

Do not schedule continuous EICAR live-checks in production.

## Quarantine operations

- Logical quarantine: `infected` or `failed`
- Owner never downloads quarantined bytes
- Admin views sanitized metadata; permanent delete preserves audit + parent email
- Rescan: failed only → pending → new job

## Monitoring signals

- Pending backlog / oldest pending age (`attachments.ops.*` thresholds)
- Failed scans last hour / retry-exhausted surge
- Infected count (24h/7d via status command)
- Queue depth on `attachment-scanning`
- Filament **Operations → Attachment Scanner**

## Retention interaction

```bash
php artisan inbound:cleanup
php artisan inbound:cleanup --confirm
```

Requires `INBOUND_RETENTION_CLEANUP_ENABLED=true`. Skips `pending`/`scanning`. Respects inbound holds. Paths must remain under `quarantine/`.

## Safety rules

- Never log attachment bytes, storage paths, or raw ClamAV responses
- Never mark clean without scanner verdict
- Private network endpoint only for `clamd`
- Integration tests: `RUN_CLAMAV_TESTS=1` with live daemon
