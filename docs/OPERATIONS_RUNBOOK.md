# Operations Runbook (Master Index)

Canonical entry point for Temail production operations (Prompt 660). Domain runbooks remain authoritative for procedure detail.

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

# Maintenance
php artisan down --secret=...
php artisan up

# Workers
php artisan queue:restart

# Health
php artisan processes:health
php artisan outbound:status
php artisan inbound:health
php artisan attachments:scanner-health
```

Suspend compromised users via Filament (revokes API keys, clears remember tokens, blocks web sessions via `web.active`).
