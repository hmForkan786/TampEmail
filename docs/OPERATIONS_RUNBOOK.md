# Operations Runbook (Master Index)

Canonical entry point for Temail production operations (Prompt 660). Domain runbooks remain authoritative for procedure detail.

## Monitoring & day-2 ops (Prompt 659)

| Doc | Purpose |
| --- | --- |
| `MONITORING_OPERATIONS_AUDIT.md` | Health/queue/scheduler/logging/ops certification |
| `OPERATIONS_MONITORING_RUNBOOK.md` | Exit codes, capacity signals, incident quick paths |
| `PRODUCTION_OPERATIONS_CHECKLIST.md` | Continuous / post-cutover ops checklist |

## Certification & deployment

| Doc | Purpose |
| --- | --- |
| `SAAS_PRODUCTION_AUDIT.md` | Full platform acceptance audit |
| `PRODUCTION_READINESS_CERTIFICATION.md` | GO / GO WITH LIMITATIONS / NO-GO certificate |
| `PRODUCTION_DEPLOYMENT_CHECKLIST.md` | Master cutover checklist |
| `PRODUCTION_RUNBOOK.md` | Platform foundation operations |
| `PRODUCTION_READINESS.md` | Local/scanner readiness notes |

## Commercial & billing

| Doc | Purpose |
| --- | --- |
| `BILLING_OPERATIONS_RUNBOOK.md` | Checkout, webhooks, renewal, grace |
| `BILLING_DEPLOYMENT_CHECKLIST.md` | Billing go-live |
| `BILLING_WEBHOOK_INCIDENT_RESPONSE.md` | Webhook incidents |
| `BILLING_WEBHOOK_KEY_ROTATION.md` | Signing key rotation |
| `COMMERCIAL_PRODUCTION_READINESS.md` | Entitlement readiness |

## Mail

| Doc | Purpose |
| --- | --- |
| `MAIL_INFRASTRUCTURE_OPERATIONS_RUNBOOK.md` | Pools / servers |
| `MAIL_SERVER_OPERATIONS_RUNBOOK.md` | Server ops |
| `INBOUND_MAIL_OPERATIONS_RUNBOOK.md` | Inbound webhook pipeline |
| `INBOX_OPERATIONS_RUNBOOK.md` | Inbox lifecycle |
| `OUTBOUND_MAIL_RUNBOOK.md` | Outbound delivery |
| `OUTBOUND_LAUNCH_RUNBOOK.md` | Launch / canary / emergency stop |
| `CLAMAV_OPERATIONS_RUNBOOK.md` | Scanner ops |

## API & security

| Doc | Purpose |
| --- | --- |
| `WEBHOOK_OPERATIONS_RUNBOOK.md` | User + provider webhooks |
| `SECURITY_OPERATIONS_RUNBOOK.md` | Account compromise, abuse, rotation |
| `API_DEPLOYMENT_CHECKLIST.md` | API/webhook workers |
| `SECURITY_DEPLOYMENT_CHECKLIST.md` | Security cutover |

## Emergency quick actions

```bash
# Outbound kill switch
OUTBOUND_EMERGENCY_STOP=true

# Billing stop (new checkouts)
STRIPE_MAINTENANCE_MODE=true
SSLCOMMERZ_MAINTENANCE_MODE=true

# Maintenance
php artisan down --secret=...
php artisan up

# Workers
php artisan queue:restart

# Health (prefer --json for automation)
php artisan platform:check --json
php artisan processes:health --json
php artisan outbound:status --json
php artisan inbound:health
php artisan attachments:scanner-health --json
php artisan mail-servers:pool-status --json
php artisan backup:restore-health --json
```

Full monitoring procedures: `OPERATIONS_MONITORING_RUNBOOK.md`.

Suspend compromised users via Filament (revokes API keys, clears remember tokens, blocks web sessions via `web.active`).
