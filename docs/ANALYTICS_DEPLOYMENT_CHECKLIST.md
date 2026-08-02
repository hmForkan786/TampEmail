# Analytics Deployment Checklist

## Pre-deploy

- [ ] Review `docs/ANALYTICS_ARCHITECTURE.md` acceptance constraints
- [ ] Confirm `ANALYTICS_*` env vars present (see `.env.example`)
- [ ] Confirm queue worker can consume `analytics` (optional for sync rollup)
- [ ] Backup DB before migration

## Env

| Key | Default | Notes |
| --- | --- | --- |
| `ANALYTICS_ENABLED` | `true` | Master switch |
| `ANALYTICS_EVENTS_RETENTION_DAYS` | `90` | Event prune |
| `ANALYTICS_ROLLUPS_RETENTION_DAYS` | `730` | Rollup prune |
| `ANALYTICS_RUNS_RETENTION_DAYS` | `180` | Run history prune |
| `ANALYTICS_SCHEDULER_ROLLUP` | `true` | Daily backfill |
| `ANALYTICS_SCHEDULER_PRUNE` | `true` | Daily prune |
| `ANALYTICS_ROLLUP_BACKFILL_DAYS` | `7` | Missing-day window |
| `ANALYTICS_ACTIVE_USER_DAYS` | `30` | Active user proxy |
| `ANALYTICS_RETENTION_COHORT_DAYS` | `30` | Retention cohort |
| `ANALYTICS_QUEUE` | `analytics` | Delayed-tolerant queue |

## Deploy

```bash
php artisan migrate --force
php artisan config:cache
php artisan analytics:health
php artisan analytics:rollup --backfill
php artisan analytics:health
```

## Smoke

- [ ] Filament **Analytics → Dashboard** loads for platform admin
- [ ] **Reports** runs daily/weekly/monthly and CSV downloads
- [ ] **Control** shows health + safe settings
- [ ] `analytics:export` writes CSV under `storage/app/analytics/exports/`
- [ ] Ads impression/click still works with analytics listeners attached
- [ ] Billing checkout unaffected

## Rollback

1. Set `ANALYTICS_ENABLED=false` (immediate pause).
2. Optionally drop analytics tables via migration rollback
   (`2026_08_01_210000_create_analytics_platform_tables`).
3. Source modules (Ads, Affiliate, Billing, Mail, API) require **no** rollback
   for Analytics — they were not modified for business logic.

## Security post-deploy

- [ ] Confirm only platform admins see Analytics nav
- [ ] Spot-check `analytics_events.dimensions` for denied PII keys
- [ ] Confirm CSV export columns are `date,domain,metric_key,value` only

## Post-deploy

- [ ] Schedule cron / worker includes Laravel scheduler
- [ ] Document on-call: `analytics:health` in ops checklist
- [ ] Link runbook: `ANALYTICS_OPERATIONS_RUNBOOK.md`
