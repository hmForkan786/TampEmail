# Mail Infrastructure Deployment Checklist

Production cutover checklist for the **app-orchestrated** mail boundary (Prompt 651). Complete before accepting real inbound provider traffic or enabling outbound send.

Companion: [MAIL_INFRASTRUCTURE_PRODUCTION_AUDIT.md](MAIL_INFRASTRUCTURE_PRODUCTION_AUDIT.md), [MAIL_INFRASTRUCTURE_OPERATIONS_RUNBOOK.md](MAIL_INFRASTRUCTURE_OPERATIONS_RUNBOOK.md).

## 1. Application environment

| Variable / setting | Required production value |
| --- | --- |
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| `APP_URL` | Canonical **HTTPS** origin |
| `QUEUE_CONNECTION` | Persistent (`database` / `redis` / SQS) — **not** `sync` |
| `CACHE_DRIVER` / lock store | `redis` or `database` for multi-process heartbeats |
| `LOG_CHANNEL` | Production stack without secret echo |
| Trusted proxies | Aligned with HTTPS reverse proxy |

## 2. HTTPS & certificates

- [ ] TLS terminated at reverse proxy / load balancer for all public HTTP(S) endpoints
- [ ] Certificate renewal monitored (proxy/ACME responsibility)
- [ ] HSTS / security headers as already applied by application middleware where configured
- [ ] Inbound webhook URL is HTTPS-only in provider configuration

## 3. Inbound webhook secrets

```env
INBOUND_GENERIC_WEBHOOK_SECRET=<strong-random>
INBOUND_WEBHOOK_TIMESTAMP_SKEW_SECONDS=300
INBOUND_WEBHOOK_MAX_BODY_BYTES=10485760
INBOUND_WEBHOOK_RATE_LIMIT_PER_MINUTE=60
```

- [ ] Secret loaded from secret manager (never committed)
- [ ] Provider configured to send `X-Inbound-Provider`, `X-Inbound-Timestamp`, `X-Inbound-Signature`, `X-Inbound-Message-Id`
- [ ] Endpoint: `POST /api/v1/inbound/webhook`

## 4. Outbound SMTP & kill switches

```env
OUTBOUND_ENABLED=false          # flip only after readiness
OUTBOUND_SEND_ENABLED=false
OUTBOUND_REPLY_ENABLED=false
OUTBOUND_FORWARD_ENABLED=false
OUTBOUND_TRANSPORT=smtp
OUTBOUND_MAILER=outbound
OUTBOUND_SMTP_HOST=
OUTBOUND_SMTP_PORT=587
OUTBOUND_SMTP_USERNAME=
OUTBOUND_SMTP_PASSWORD=
OUTBOUND_SMTP_ENCRYPTION=tls
OUTBOUND_SMTP_VERIFY_PEER=true
OUTBOUND_SMTP_REQUIRE_AUTH=true
OUTBOUND_EMERGENCY_STOP=true
OUTBOUND_ROLLOUT_MODE=disabled
OUTBOUND_DOMAIN_AUTH_ENFORCE=true
```

- [ ] SMTP credentials only in secret store
- [ ] Encryption is `tls` or `ssl` (not open relay / empty in prod)
- [ ] `verify_peer=true` unless an approved exception exists

## 5. DNS authentication (SPF / DKIM / DMARC)

Operational requirement for each outbound-enabled domain:

| Record | Purpose |
| --- | --- |
| SPF | Authorize sending infrastructure / provider includes |
| DKIM | Provider tokens / CNAMEs (e.g. SES) as documented for the chosen provider |
| DMARC | Policy aligned with launch risk tolerance |

- [ ] Publish records at DNS host
- [ ] Run / wait for `outbound:verify-domains` (scheduled hourly) or Filament verify
- [ ] Confirm domain auth state allows send before enabling rollout
- [ ] SES: configure `OUTBOUND_SES_*` token/include settings when using SES

No live DNS probing is required for *this checklist's authoring*; production deploy **must** validate DNS before enabling outbound.

## 6. Queues & workers

```env
QUEUE_ATTACHMENT_SCANNING=attachment-scanning
QUEUE_OUTBOUND_DELIVERY=outbound-delivery
QUEUE_OUTBOUND_EVENTS=outbound-events
QUEUE_OUTBOUND_MAINTENANCE=outbound-maintenance
QUEUE_NOTIFICATIONS=notifications
```

- [ ] Workers cover **default** (inbound `ProcessInboundMessageJob`), attachment-scanning, outbound-*, notifications as used
- [ ] Supervisor programs installed from `deploy/supervisor/*.conf.example`
- [ ] Outbound queues isolated from inbound where topology requires
- [ ] Failed job driver configured (`database-uuids` default)

## 7. Scheduler

- [ ] Exactly one of: `schedule:work` **or** cron `* * * * * … schedule:run`
- [ ] `php artisan schedule:list` shows outbound verify/reconcile/dispatch and billing lifecycle commands
- [ ] Feature flags reviewed: inbound cleanup, inbox expire, outbound prune

## 8. Secrets inventory (mail-related)

| Secret | Usage |
| --- | --- |
| `INBOUND_GENERIC_WEBHOOK_SECRET` | Inbound HMAC |
| `OUTBOUND_SMTP_PASSWORD` | SMTP AUTH |
| `OUTBOUND_GENERIC_DELIVERY_WEBHOOK_SECRET` | Outbound delivery webhook (if used) |
| Provider-specific SES/SNS materials | As configured |
| `APP_KEY` | Encryption at rest for sensitive attributes |

## 9. Pre-deploy commands

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan migrate --force
php artisan schedule:list
php artisan inbound:health
php artisan outbound:launch-readiness --json
php artisan processes:runtime-smoke --json
```

## 10. Runtime processes

- [ ] Web (PHP-FPM / Octane / equivalent) behind HTTPS
- [ ] Queue workers (inbound-capable + outbound + notifications as required)
- [ ] Scheduler
- [ ] Optional ClamAV sidecar if `attachments.scanner_backend=clamav`
- [ ] Log shipping / disk monitoring for private attachment storage

## 11. Backup & restore

- [ ] Database backups (logical + PITR/binlog as applicable)
- [ ] Private disks for attachments / raw retention backed up
- [ ] Restore drill documented; post-restore health commands listed in ops runbook
- [ ] Rollback plan: revert release artifact; do **not** drop mail/billing tables to undo traffic

## 12. Smoke tests after deploy

1. **Inbound:** provider or controlled signed POST → `202` → message appears in target inbox (or expected reject code for unknown recipient).
2. **Duplicate:** replay same `X-Inbound-Message-Id` → accepted without duplicate persisted row (metrics `duplicate`).
3. **Outbound (canary only):** with launch controls allowing canary, `outbound:launch-readiness` ready, then explicit canary send to allowlisted recipient.
4. **Health:** `processes:health` healthy; no unbounded failed jobs.
5. **Billing sanity (coupling):** one gateway health command if billing enabled.

## 13. Explicit non-goals for this deploy

Do **not** block go-live on:

- Installing Postfix/Exim/Dovecot on app hosts
- Native SMTP listener / MX pointing at the Laravel app
- New SMTP/MX probe artisan commands

Those remain deferred by architecture (see audit deferred register).
