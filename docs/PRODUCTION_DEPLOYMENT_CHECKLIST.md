# Production Deployment Checklist (Prompt 660)

Master cutover checklist. Complete subsystem checklists as referenced. Do **not** deploy using `.env.example` unchanged.

## A. Pre-flight

- [ ] MySQL/PostgreSQL (not SQLite) for production
- [ ] Redis for cache/queue/session (recommended)
- [ ] Migrations applied: `php artisan migrate --force`
- [ ] `php artisan storage:link` if public assets needed
- [ ] Commercial plan/feature seeder run
- [ ] `composer audit` reviewed; advisories accepted or scheduled

## B. Environment harden

- [ ] `APP_ENV=production`
- [ ] `APP_DEBUG=false`
- [ ] Strong `APP_KEY` and `API_KEY_HASH_SECRET`
- [ ] HTTPS + `SESSION_SECURE_COOKIE=true`
- [ ] `SESSION_ENCRYPT=true`
- [ ] `SECURITY_HSTS_ENABLED=true` when HTTPS is end-to-end
- [ ] TrustProxies/Hosts configured for the edge
- [ ] Logging to durable store; security/audit channels writable

## C. Billing

- [ ] Follow `BILLING_DEPLOYMENT_CHECKLIST.md`
- [ ] Remove `fake` from enabled gateways
- [ ] Live Stripe and/or SSLCommerz credentials
- [ ] Webhook URLs + signature secrets verified
- [ ] `billing:stripe-health` / `billing:sslcommerz-health` PASS
- [ ] Renewal/grace/expire/checkout expire scheduler running

## D. Mail

- [ ] `MAIL_INFRASTRUCTURE_DEPLOYMENT_CHECKLIST.md`
- [ ] `INBOUND_MAIL_DEPLOYMENT_CHECKLIST.md`
- [ ] `INBOX_DEPLOYMENT_CHECKLIST.md`
- [ ] `OUTBOUND_MAIL_DEPLOYMENT_CHECKLIST.md` (launch sequence)
- [ ] `ATTACHMENT_DEPLOYMENT_CHECKLIST.md` + ClamAV health before enable
- [ ] Mail-server pool capacity + `mail-servers:refresh-ha`

## E. API & webhooks

- [ ] `API_DEPLOYMENT_CHECKLIST.md`
- [ ] Workers: `webhooks`, `outbound-events`, `attachment-scanning`, outbound-delivery, notifications, default
- [ ] User webhook entitlement on entitled plans only

## F. Security

- [ ] `SECURITY_DEPLOYMENT_CHECKLIST.md`
- [ ] Login throttle observed
- [ ] Suspended user cannot keep web session
- [ ] No secrets in logs smoke test

## G. Workers (Supervisor examples under `deploy/supervisor/`)

- [ ] `default` (inbound processing)
- [ ] `attachment-scanning`
- [ ] `outbound-delivery`
- [ ] `outbound-events`
- [ ] `webhooks`
- [ ] `notifications`
- [ ] Billing-related queues if not sync

## H. Scheduler

```bash
php artisan schedule:list
# cron: * * * * * php /path/to/artisan schedule:run
```

- [ ] Heartbeat + billing + outbound reconcile/dispatch present
- [ ] Enable retention/expire flags intentionally

## I. Caches

```bash
php artisan config:cache
php artisan route:cache
php artisan event:cache
php artisan view:cache
```

- [ ] Succeeds with production env
- [ ] Clear route/config cache after env changes

## J. Health smoke

```bash
php artisan platform:check
php artisan inbound:health
php artisan outbound:status --json
php artisan outbound:launch-readiness
php artisan attachments:scanner-health --json
php artisan billing:stripe-health   # if Stripe
php artisan processes:health
```

- [ ] All in-scope commands healthy
- [ ] Canary outbound send (if launching outbound)
- [ ] Sample inbound webhook accepted
- [ ] Sample checkout on live gateway (small amount)

## K. Rollback

- [ ] Previous release artifact / git tag known
- [ ] DB backup verified (`backup:restore-health` if used)
- [ ] Kill switches known: `OUTBOUND_EMERGENCY_STOP`, disable gateways, `APP_MAINTENANCE`
- [ ] Worker stop/start procedure documented for on-call

## L. Day-1 watch

- [ ] Queue depths
- [ ] Failed jobs
- [ ] Billing webhook failures
- [ ] Bounce/complaint rates (if outbound live)
- [ ] Attachment pending backlog
- [ ] Scheduler heartbeat

## Decision gate

Only after A–J: mark environment **ready for traffic** under `PRODUCTION_READINESS_CERTIFICATION.md` (**GO WITH LIMITATIONS**).
