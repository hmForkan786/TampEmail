# Operations Monitoring Runbook (Prompt 659)

Day-2 monitoring procedures for Temail. Domain runbooks remain authoritative for deep remediation; this document is the **monitoring and stop/start** playbook.

Master index: `OPERATIONS_RUNBOOK.md`  
Audit: `MONITORING_OPERATIONS_AUDIT.md`  
Checklist: `PRODUCTION_OPERATIONS_CHECKLIST.md`

---

## Safe verification (read-only)

```bash
php artisan platform:check --json
php artisan processes:health --json
php artisan processes:runtime-smoke --json
php artisan inbound:health
php artisan outbound:status --json
php artisan outbound:launch-readiness --json
php artisan billing:stripe-health
php artisan billing:sslcommerz-health
php artisan attachments:scanner-health --json
php artisan attachments:scanner-status --json
php artisan mail-servers:pool-status --json
php artisan backup:restore-health --json
php artisan queue:failed
php artisan schedule:list
```

Do not paste credentials, private hosts, job payloads, email bodies, or signing secrets into tickets.

---

## Exit-code contract (alerting)

| Command | 0 | 1 | 2 |
| --- | --- | --- | --- |
| `platform:check` | healthy | failed | — |
| `processes:health` | healthy | degraded **or** failed | — |
| `inbound:health` | healthy | degraded **or** failed | — |
| `outbound:status` | healthy | failed | degraded / unknown |
| `outbound:launch-readiness` | ready | blocked | degraded / disabled |
| `billing:*:health` | healthy | unhealthy | — |
| `attachments:scanner-health` | healthy | failed | disabled |
| `attachments:scanner-status` | healthy | failed | degraded |
| `attachments:scanner-live-check` | healthy | failed | unavailable |
| `backup:restore-health` | ready | failed | degraded |
| `processes:runtime-smoke` | healthy | failed | degraded |
| `mail-servers:pool-status` | always | — | — |

Parse JSON `status` when available. For `mail-servers:pool-status`, alert on `summary.unhealthy > 0` or `summary.eligible = 0` under expected load.

---

## Queue monitoring

1. Run `processes:health --json` — check `queue.backlog`, `failed_jobs`, `oldest_job_age_seconds`, worker/scheduler freshness.  
2. Inspect `php artisan queue:failed` (do not dump payloads).  
3. Confirm Supervisor is consuming the intended queue names (`attachment-scanning`, `outbound-delivery`, `outbound-events`, `webhooks`, `notifications`, inbound/default, billing).  
4. Domain follow-ups: `outbound:status`, `attachments:scanner-status`, `inbound:health`.

Retry only after root cause is fixed: `php artisan queue:retry <id>` per deployment policy. Never purge queues as a first response.

---

## Scheduler monitoring

1. Exactly one strategy: supervised `schedule:work` **or** cron `schedule:run` (not both).  
2. Expect fresh `processes:scheduler-heartbeat` within `PROCESS_HEARTBEAT_TTL_SECONDS` (default 180).  
3. `php artisan schedule:list` after deploy.  
4. Stale scheduler heartbeat → treat as degraded/failed via `processes:health` until resolved.

---

## Maintenance and emergency stops

```bash
# Application maintenance (bypass with secret for operators)
php artisan down --secret=<DEPLOYMENT_SECRET>
php artisan up

# Outbound kill switch (env and/or Filament Launch Control)
OUTBOUND_EMERGENCY_STOP=true

# Billing: refuse new provider checkouts
STRIPE_MAINTENANCE_MODE=true
SSLCOMMERZ_MAINTENANCE_MODE=true

# Scanner fail-closed
ATTACHMENT_SCANNER_BACKEND=disabled

# Mail server inventory drain
php artisan mail-servers:ops transition <uuid> maintenance --reason=incident
php artisan mail-servers:ops transition <uuid> draining --reason=incident
```

After stops: `queue:restart`, verify health JSON, then lift stops in reverse dependency order (storage/DB → queues → billing → outbound → traffic).

---

## Capacity signals

| Signal | Command / field |
| --- | --- |
| Queue depth / age | `processes:health` → `queue.backlog`, `oldest_job_age_seconds` |
| Failed job growth | `processes:health` → `queue.failed_jobs` + `queue:failed` |
| Outbound volume / bounce | `outbound:status` → `volume`, `provider` |
| Scanner backlog / quarantine | `attachments:scanner-status` |
| Pool utilization | `mail-servers:pool-status` → `capacity`, `summary` |
| Storage integrity | `backup:restore-health` |
| Inbox / invoice growth | Database / commercial APIs (manual ops) |

---

## Incident quick paths

### Database outage

1. Confirm app/DB connectivity; keep workers from retry storms if appropriate (`queue:restart` after pause).  
2. Preserve redacted health output.  
3. Restore only via approved platform backup procedure; verify with `backup:restore-health` in drill environments.  
4. See `PRODUCTION_RUNBOOK.md` rollback section.

### Queue outage / Redis unavailable

1. `processes:health --json` — expect failed/degraded.  
2. Verify queue connection and Supervisor.  
3. Do not switch production to `sync`.  
4. See `PROCESS_OPERATIONS.md`.

### SMTP / outbound outage

1. Engage outbound emergency stop if sending must halt.  
2. `outbound:status --json`, `outbound:launch-readiness --json`.  
3. See `OUTBOUND_MAIL_RUNBOOK.md` / `OUTBOUND_LAUNCH_RUNBOOK.md`.

### Payment outage

1. Enable provider maintenance modes if new checkouts must stop.  
2. `billing:stripe-health` / `billing:sslcommerz-health`.  
3. See `BILLING_OPERATIONS_RUNBOOK.md` / `BILLING_WEBHOOK_INCIDENT_RESPONSE.md`.

### Webhook outage

1. Check provider vs user webhook workers and `queue:failed`.  
2. See `WEBHOOK_OPERATIONS_RUNBOOK.md`.

### Storage / attachment outage

1. `backup:restore-health --json`, scanner health.  
2. Keep scanner disabled if integrity unknown.  
3. See `PRODUCTION_RUNBOOK.md`, `CLAMAV_OPERATIONS_RUNBOOK.md`.

### Malware event

1. Quarantine remains non-downloadable for clean-only policy.  
2. Follow `CLAMAV_OPERATIONS_RUNBOOK.md` and `SECURITY_OPERATIONS_RUNBOOK.md`.  
3. Do not paste sample bytes into tickets.

---

## Logging review

Review `storage/logs/laravel.log`, `security.log`, `audit.log`, `queue.log`, `ingestion.log` (and Supervisor stdout paths). Confirm rotation and access controls at the deployment layer. Redact before sharing.

---

## After deploy

1. Config / route / event cache  
2. `queue:restart`  
3. Reload Supervisor workers + single scheduler  
4. Warm-up one scheduler minute  
5. Run safe verification block above  
6. Confirm no sensitive values in health JSON or log samples
