# Attachment Deployment Checklist

Pre-production checklist for private attachment storage and ClamAV scanning (Prompt 656). Complements `ATTACHMENT_SECURITY_AUDIT.md` and `CLAMAV_OPERATIONS_RUNBOOK.md`.

## Prerequisites

- [ ] Migrations applied (`attachments` scan columns, soft deletes)
- [ ] `attachments` filesystem disk private (`storage/app/private/attachments`, `visibility=private`)
- [ ] Directory writable by app / queue workers; not web-root served
- [ ] API scopes: `inboxes:read` for inbound download; outbound scopes for forward downloads

## Fail-closed defaults

```env
ATTACHMENT_SCANNER_BACKEND=disabled
```

With backend disabled, new attachments become `skipped` and **must not** be downloadable. Flip to `clamav` only after daemon + workers are ready.

## ClamAV daemon

- [ ] `clamd` installed and running on private network / Unix socket
- [ ] Signature databases updating (freshclam / vendor process)
- [ ] `ATTACHMENT_CLAMAV_HOST` / `PORT` or `ATTACHMENT_CLAMAV_SOCKET` set
- [ ] Connect/read timeouts reviewed
- [ ] Smoke:

```bash
php artisan attachments:scanner-health --json
php artisan attachments:scanner-live-check --json
```

## Application config

- [ ] `ATTACHMENT_SCAN_MAX_BYTES` ≤ ingress limits (default 25 MiB)
- [ ] `ATTACHMENT_MAX_COUNT` / `ATTACHMENT_MAX_TOTAL_BYTES` set
- [ ] `ATTACHMENT_SCAN_MAX_ATTEMPTS` and backoff set
- [ ] Ops thresholds (`ATTACHMENT_SCAN_PENDING_BACKLOG_THRESHOLD`, etc.) understood
- [ ] `QUEUE_ATTACHMENT_SCANNING=attachment-scanning` (or documented override)

## Workers

- [ ] Supervisor/systemd worker consumes `attachment-scanning`
- [ ] Distinct from outbound-delivery / notifications queues
- [ ] Failed jobs table monitored
- [ ] Job timeout ≥ scanner timeout + buffer (job clamps 30–300s)

## Scheduler / retention

- [ ] `INBOUND_RETENTION_CLEANUP_ENABLED` intentional
- [ ] `inbound:cleanup` scheduled when enabled (`withoutOverlapping`)
- [ ] Holds workflow known (blocks cleanup/purge)

```bash
php artisan schedule:list
```

## Security smoke test

1. Ingest email with harmless attachment → `pending` → (with ClamAV) `clean` → owner download 200  
2. Pending/scanning/failed/infected/skipped → download 404  
3. `clean` + `is_safe=false|null` → 404  
4. Foreign owner / missing scope → 404 / 403  
5. Expired or deleted inbox → 404  
6. Path traversal / disk mismatch → 404  
7. EICAR (lab only) → `infected`, quarantine admin visible, owner download 404  
8. Disable scanner → new attachments `skipped`, undownloadable  
9. Confirm no public URL for `attachments` disk  
10. API request logs contain no file bytes

## Admin / ops

- [ ] Filament Attachment Scanner Ops accessible to platform admins only  
- [ ] Quarantined Attachments list hides raw storage paths  
- [ ] Rescan / permanent delete audited  
- [ ] Live-check rate limit acceptable  

## Config / route cache

```bash
php artisan config:cache
php artisan route:cache
```

- [ ] Cache succeeds with production env  
- [ ] After local verification, `config:clear` if needed  
- [ ] `git diff --check` clean on release branch  

## Post-deploy

```bash
php artisan attachments:scanner-health --json
php artisan attachments:scanner-status --json
```

- [ ] Health `healthy` before raising traffic expectations  
- [ ] Pending backlog below ops threshold  
- [ ] No unexpected infected surge  
- [ ] Workers and scheduler heartbeats healthy  

## Explicit non-goals (do not block deploy)

- New antivirus / cloud malware engines  
- Archive deep expansion beyond current ClamAV stream scan  
- Commercial entitlement redesign  
- Public attachment CDN
