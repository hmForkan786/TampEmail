# Monitoring, Observability & Operations Audit (Prompt 659)

**Audit date:** 2026-08-01  
**Scope:** Operational observability, health gates, queue/scheduler monitoring, logging hygiene, backup readiness, maintenance controls, incident runbooks, deployment validation.  
**Architecture changes:** None (audit + documentation + focused regression; `platform:check --json` only).  
**Decision:** **PASS** with accepted limitations.

Companion docs:

- `OPERATIONS_MONITORING_RUNBOOK.md` — operator day-2 procedures  
- `PRODUCTION_OPERATIONS_CHECKLIST.md` — cutover / continuous ops checklist  
- `OPERATIONS_RUNBOOK.md` — master index (Prompt 660)

---

## Acceptance map

| # | Scope | Verdict | Notes |
| --- | --- | --- | --- |
| 1 | Health Platform | **PASS** | Commands verified; exit-code contract documented |
| 2 | Queue Monitoring | **PASS** | Aggregate via `processes:health`; domain depth via outbound/scanner |
| 3 | Scheduler Monitoring | **PASS** | Heartbeat + `withoutOverlapping`; no drift subsystem (accepted) |
| 4 | Metrics | **PASS** | Existing inbound/outbound/scanner/pool metrics; no new platform |
| 5 | Operational Commands | **PASS** | Inventory below |
| 6 | Logging | **PASS** | Channels + secret hygiene rules documented |
| 7 | Backup Readiness | **PASS** | `backup:restore-health` + restore/rollback docs |
| 8 | Maintenance Mode | **PASS** | Laravel down + outbound/billing/scanner/pool stops |
| 9 | Capacity Monitoring | **PASS** | Signal index (commands/fields); no unified capacity CLI |
| 10 | Incident Response | **PASS** | Cross-linked playbooks |
| 11 | Deployment Validation | **PASS** | Cache + workers + scheduler; Supervisor (no Horizon) |
| 12 | Regression | **PASS** | Focused suite listed in checklist |
| 13 | Tooling | **PASS** | Pint / PHPStan / caches / `schedule:list` / `git diff --check` |

---

## 1. Health platform

| Command | JSON | Exit contract | Fail-closed |
| --- | --- | --- | --- |
| `platform:check` | `--json` | healthy=`0`, failed=`1` | Missing foundation keys → failed |
| `processes:health` | `--json` | healthy=`0`; **degraded and failed = `1`** | Catch → `failed` + exit `1` |
| `inbound:health` | always JSON | healthy=`0`; degraded/failed=`1` | Catch → `metrics_unavailable` |
| `outbound:status` | `--json` | healthy=`0`, degraded/unknown=`2`, failed=`1` | Catch → failed summary |
| `outbound:launch-readiness` | `--json` | ready=`0`, degraded/disabled=`2`, blocked=`1` | Catch → blocked |
| `billing:stripe-health` | always JSON | healthy=`0`/`1` | Catch → `healthy:false` |
| `billing:sslcommerz-health` | always JSON | healthy=`0`/`1` | Catch → `healthy:false` |
| `attachments:scanner-health` | `--json` | healthy=`0`, disabled=`2`, else=`1` | Catch → failed |
| `attachments:scanner-status` | `--json` | healthy=`0`, degraded=`2`, failed=`1` | Catch → failed |
| `attachments:scanner-live-check` | `--json` | healthy=`0`, unavailable=`2`, failed=`1` | Catch → failed |
| `mail-servers:pool-status` | `--json` | **always `0`** — parse `summary.unhealthy` / `eligible` | DB errors bubble |
| `backup:restore-health` | `--json` | ready=`0`, degraded=`2`, failed=`1` | Catch → failed |
| `processes:runtime-smoke` | `--json` | healthy=`0`, degraded=`2`, failed=`1` | Catch → failed |

**Alerting guidance:** Prefer parsing JSON `status` over exit codes when mixing commands. Treat `processes:health` / `inbound:health` exit `1` as “not healthy” (covers both degraded and failed). Treat `outbound:status` exit `2` as warning, `1` as critical.

---

## 2. Queue monitoring

**Aggregate gate:** `php artisan processes:health --json`

Reports:

- `queue.connection`, `queue.workloads`
- `queue.failed_jobs`, `queue.backlog`, `queue.oldest_job_age_seconds`
- Worker heartbeat freshness / expected count
- Threshold breaches → `degraded` (still exit `1`)

**Named workloads** (`config/queue.php`): `mail-ingestion`, `mail-parsing`, `mail-storage`, `attachment-scanning`, `outbound-delivery`, `outbound-events`, `outbound-maintenance`, `notifications`, `webhooks`, `analytics`, `exports`, plus `default`.

**Domain depth:**

| Workload | Primary signal |
| --- | --- |
| Inbound (default + ingestion) | `inbound:health`, `processes:health` backlog |
| Outbound delivery/events/maintenance | `outbound:status --json` |
| Attachment scanning | `attachments:scanner-status --json` |
| Webhooks / notifications | `queue:failed` + worker heartbeats for those queues |
| Billing | Jobs on `BILLING_QUEUE_*` (default queue unless overridden) |

**Accepted limit:** No single per-queue depth CLI and no Horizon. Operators use Supervisor queue lists + `processes:health` + domain commands.

---

## 3. Scheduler monitoring

- Every scheduled entry in `bootstrap/app.php` uses `withoutOverlapping()`.
- `processes:scheduler-heartbeat` runs every minute; freshness consumed by `processes:health`.
- Outbound overdue scheduled messages appear in `outbound:status` / ops metrics.

**Accepted limit:** No dedicated scheduler-drift or missed-execution counter subsystem. Stale heartbeat is the production signal; dual `schedule:work` + cron is forbidden (`PROCESS_OPERATIONS.md`).

---

## 4. Metrics (existing only)

| Domain | Source | Signals |
| --- | --- | --- |
| Inbound | `InboundMetricsService` / `inbound:health` | received, processing, holds, breaches |
| Outbound | `OutboundOpsService` | sent, failed, retries, bounce/complaint, suppressions, queue age |
| Attachments | scanner status/health | pending, clean, infected, quarantine, queue |
| Mail servers | `mail-servers:pool-status` | utilization, eligible, unhealthy |
| Billing | provider health commands | reachability only (not volume APM) |
| Webhooks | delivery models + failed jobs | latency not first-class metric |

No SIEM / Prometheus exporter / metrics warehouse added in Prompt 659.

---

## 5. Operational command inventory

```text
platform:check [--json]
processes:health [--json]
processes:runtime-smoke [--json]
processes:verification-preflight [--json]
processes:scheduler-heartbeat
inbound:health
outbound:status [--json]
outbound:launch-readiness [--json]
billing:stripe-health
billing:sslcommerz-health
billing:reconcile [--dry-run]
billing:sync-payment-status
attachments:scanner-health [--json]
attachments:scanner-status [--json]
attachments:scanner-live-check [--json]
mail-servers:pool-status [--json]
mail-servers:refresh-ha
mail-servers:ops …
backup:restore-health [--json]
queue:failed
schedule:list
```

---

## 6. Logging

| Channel | Path pattern | Use |
| --- | --- | --- |
| `stack` / `single` / `daily` | `storage/logs/laravel.log` | Application |
| `security` | `storage/logs/security.log` | Auth / abuse / security events |
| `audit` | `storage/logs/audit.log` | Audit writer channel |
| `queue` | `storage/logs/queue.log` | Queue worker signals |
| `ingestion` | `storage/logs/ingestion.log` | Inbound ingestion |

**Hygiene (binding):** Never log message bodies, raw MIME, attachment bytes, API keys, payment secrets, webhook signing material, `.env` values, private hostnames, or serialized job payloads in tickets. Health JSON must remain free of credentials (existing safe summaries).

**Accepted limit:** No dedicated `billing` / `webhook` / `scheduler` Monolog channels; those domains use application/audit/security channels and domain tables.

---

## 7. Backup readiness

- Gate: `php artisan backup:restore-health --json`
- Procedure: `PRODUCTION_RUNBOOK.md` (Backup and restore / Rollback)
- Scope: database + private attachment/message-body disks + operator manifest under private `local` disk `backup-restore/`
- Command never exports or restores production data

---

## 8. Maintenance / stop controls

| Control | Mechanism |
| --- | --- |
| Application maintenance | `php artisan down --secret=…` / `up` |
| Outbound emergency stop | `OUTBOUND_EMERGENCY_STOP` + Filament launch control |
| Billing stop (new checkouts) | `STRIPE_MAINTENANCE_MODE`, `SSLCOMMERZ_MAINTENANCE_MODE` |
| Scanner stop | `ATTACHMENT_SCANNER_BACKEND=disabled` (default fail-closed) |
| Mail server drain | `mail-servers:ops transition … maintenance\|draining\|disabled` |

---

## 9. Capacity signal index

| Capacity concern | How to observe |
| --- | --- |
| Inbox count | DB / Filament-adjacent ops; no dedicated CLI |
| Mail server utilization | `mail-servers:pool-status --json` → `capacity` / `summary` |
| Queue depth / oldest age | `processes:health --json` → `queue.backlog`, `oldest_job_age_seconds` |
| Storage / restore path integrity | `backup:restore-health --json` |
| Attachment growth / quarantine | `attachments:scanner-status --json` |
| Invoice growth | Billing DB / invoices API; no capacity CLI |

---

## 10. Incident response index

| Incident | Primary runbook |
| --- | --- |
| SMTP / outbound outage | `OUTBOUND_MAIL_RUNBOOK.md`, `OUTBOUND_LAUNCH_RUNBOOK.md` |
| Payment outage | `BILLING_OPERATIONS_RUNBOOK.md`, `BILLING_WEBHOOK_INCIDENT_RESPONSE.md` |
| Webhook outage | `WEBHOOK_OPERATIONS_RUNBOOK.md` |
| Database outage | `PRODUCTION_RUNBOOK.md` + `OPERATIONS_MONITORING_RUNBOOK.md` |
| Queue outage | `PROCESS_OPERATIONS.md` |
| Storage outage | `PRODUCTION_RUNBOOK.md`, attachment runbooks |
| Malware / scanner event | `CLAMAV_OPERATIONS_RUNBOOK.md`, `SECURITY_OPERATIONS_RUNBOOK.md` |

---

## 11. Deployment validation

```bash
php artisan config:cache
php artisan route:cache
php artisan event:cache
php artisan schedule:list
php artisan processes:health --json
php artisan queue:failed
```

Workers: Supervisor templates under `deploy/supervisor/` (including outbound-isolated workers). **Horizon is not used.**

---

## 12. Regression suite (Prompt 659)

```bash
php artisan test --filter=PlatformFoundationTest
php artisan test --filter=ProcessHealthTest
php artisan test --filter=ProcessRuntimeSmokeTest
php artisan test --filter=InboundMetricsHealthTest
php artisan test --filter=AttachmentScannerHealthTest
php artisan test --filter=BackupRestoreHealthTest
php artisan test --filter=OutboundQueueProcessHealthTest
php artisan test --filter=MailServerPoolHaTest
php artisan test --filter=BillingProviderHealthCommandTest
php artisan test --filter=MonitoringOperationsDocumentationTest
php artisan test --filter=MonitoringOperationsRegressionTest
```

Or grouped:

```bash
php artisan test tests/Feature/MonitoringOperationsRegressionTest.php tests/Feature/BillingProviderHealthCommandTest.php tests/Feature/MonitoringOperationsDocumentationTest.php
```

---

## 13. Tooling

```bash
vendor/bin/pint --test
vendor/bin/phpstan analyse   # historical baseline may still be red; not a 659 blocker
php artisan config:cache
php artisan route:cache
php artisan schedule:list
git diff --check
```

---

## Accepted limitations (binding)

1. No Prometheus / Grafana / PagerDuty / SIEM product integration.  
2. No Laravel Horizon.  
3. No scheduler drift detector beyond heartbeat freshness.  
4. No unified capacity dashboard CLI.  
5. Billing/webhook volume latency metrics are not first-class.  
6. `mail-servers:pool-status` never fails on unhealthy pools — operators must parse JSON.  
7. `processes:health` / `inbound:health` collapse degraded into exit `1` (by design for fail-closed alerting).

---

## Sign-off

Prompt 659 **PASS**. Production day-2 operations are documented and regression-covered for the existing health surface. Prompt 660 full SaaS certification remains the release gate; this prompt certifies the monitoring/ops platform layer specifically.
