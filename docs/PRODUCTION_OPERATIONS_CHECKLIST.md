# Production Operations Checklist (Prompt 659)

Use with `PRODUCTION_DEPLOYMENT_CHECKLIST.md` (cutover) and `OPERATIONS_MONITORING_RUNBOOK.md` (day-2). Mark each item when verified in the target environment.

## A. Foundation & caches

- [ ] `APP_ENV=production`, `APP_DEBUG=false`, HTTPS `APP_URL`
- [ ] Secrets loaded from secret manager (not committed `.env`)
- [ ] `php artisan config:cache`
- [ ] `php artisan route:cache`
- [ ] `php artisan event:cache`
- [ ] `php artisan platform:check --json` → `status=healthy`
- [ ] `php artisan schedule:list` reviewed
- [ ] `git diff --check` clean on release artifact (CI)

## B. Workers & scheduler

- [ ] Durable queue (`redis` or `database`, not `sync`)
- [ ] Compatible cache/lock store (`redis` or `database`)
- [ ] Supervisor templates applied (`deploy/supervisor/*`)
- [ ] Required queues consuming: inbound/default, `attachment-scanning`, outbound delivery/events/maintenance as enabled, `webhooks`, `notifications`, billing
- [ ] Exactly one scheduler strategy
- [ ] `php artisan queue:restart` after release
- [ ] Heartbeat warm-up completed (≥1 scheduler minute)
- [ ] `php artisan processes:health --json` → `healthy` (or approved degraded documented)
- [ ] `php artisan processes:runtime-smoke --json` acceptable
- [ ] `php artisan queue:failed` reviewed (empty or known)

## C. Domain health

- [ ] `php artisan inbound:health` → healthy or approved degraded
- [ ] `php artisan outbound:status --json` (if outbound in scope)
- [ ] `php artisan outbound:launch-readiness --json` matches launch policy
- [ ] `php artisan billing:stripe-health` (if Stripe enabled)
- [ ] `php artisan billing:sslcommerz-health` (if SSLCommerz enabled)
- [ ] Fake payment gateway disabled in production enabled set
- [ ] `php artisan attachments:scanner-health --json` (disabled=`2` OK until ClamAV gate)
- [ ] `php artisan mail-servers:pool-status --json` — eligible capacity adequate
- [ ] `php artisan backup:restore-health --json` → `ready` or approved `degraded` (manifest)

## D. Maintenance controls verified

- [ ] `php artisan down` / `up` procedure known (secret stored safely)
- [ ] Outbound emergency stop procedure known (env + Filament)
- [ ] Billing maintenance env flags known
- [ ] Scanner remains `disabled` until ClamAV gate green
- [ ] Mail server drain/transition procedure known

## E. Logging & secrets

- [ ] Log channels writable; rotation configured at host
- [ ] Sample health JSON contains no secrets/hosts/paths beyond safe fields
- [ ] Operators briefed: no MIME/bodies/keys in tickets

## F. Backup / DR

- [ ] Database backup job owned by platform team
- [ ] Private attachment / message-body storage backup owned
- [ ] Isolated restore drill referenced; manifest process understood
- [ ] Rollback order documented (`PRODUCTION_RUNBOOK.md`)

## G. Incident readiness

- [ ] `OPERATIONS_MONITORING_RUNBOOK.md` available to on-call
- [ ] Billing / outbound / webhook / ClamAV / process runbooks linked
- [ ] Escalation owners for DB, queue, storage, mail, payments identified

## H. Tooling (release CI / pre-prod)

- [ ] `vendor/bin/pint --test` (or project script)
- [ ] PHPStan: known baseline accepted or scoped green
- [ ] Prompt 659 regression filters green (see `MONITORING_OPERATIONS_AUDIT.md` §12)

## Sign-off

| Role | Name | Date | Notes |
| --- | --- | --- | --- |
| Operator | | | |
| Engineering | | | |

**Prompt 659 status:** complete when sections A–H are checked for the target environment (local/dev may leave production-only items N/A with reason).
