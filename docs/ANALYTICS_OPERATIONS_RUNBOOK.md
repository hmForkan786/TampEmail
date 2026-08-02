# Analytics Operations Runbook

## Daily checks

```bash
php artisan analytics:health
```

Expect JSON with `"healthy": true`. Exit code `0` = healthy, `1` = degraded.

Watch:

* `backlog_days` — missing successful rollups in the backfill window
* `failed_runs_24h` — aggregation exceptions
* `last_success_at` / `last_failure_at`

Admin UI: **Analytics → Control**.

## Scheduler

Configured in `bootstrap/app.php` (gated by config):

| Command | Cadence | Toggle |
| --- | --- | --- |
| `analytics:rollup --backfill` | daily 01:15 | `ANALYTICS_SCHEDULER_ROLLUP` |
| `analytics:prune --confirm` | daily 02:15 | `ANALYTICS_SCHEDULER_PRUNE` |

Manual day rollup:

```bash
php artisan analytics:rollup --date=2026-07-31
php artisan analytics:rollup --backfill
```

## Reports & CSV

```bash
php artisan analytics:export --period=weekly --from=2026-07-01 --to=2026-07-31
```

Or use **Analytics → Reports** → CSV export (admin session).

## Incidents

### Aggregation backlog

1. Confirm scheduler heartbeat (`processes:scheduler-heartbeat` / Process Health).
2. Run `php artisan analytics:rollup --backfill`.
3. Re-check `analytics:health`.

### Failed aggregation

1. Inspect `analytics_aggregation_runs.error_message`.
2. Fix underlying DB/schema issue (Analytics never patches Billing/Mail).
3. Re-run rollup for the failed `bucket_date`.

### Collector noise

Ads/subscription listeners are fail-open. Warnings are logged as
`analytics.*_ingest_failed` and must not block product traffic.

### Kill / pause

Set `ANALYTICS_ENABLED=false`. Collectors and rollups no-op; dashboards show
empty/zero read models. Source modules continue unchanged.

## Monitoring hooks

* Health command for uptime checks
* Filament Control page counters
* Aggregation run table for audit of successes/failures

## Retention

| Store | Default days | Env |
| --- | --- | --- |
| Events | 90 | `ANALYTICS_EVENTS_RETENTION_DAYS` |
| Rollups | 730 | `ANALYTICS_ROLLUPS_RETENTION_DAYS` |
| Runs | 180 | `ANALYTICS_RUNS_RETENTION_DAYS` |

Prune requires `--confirm`.

## Safety reminders

* Analytics does **not** mutate Billing, Mail, or API responses.
* Exports contain aggregated metrics only (no PII columns).
* Queue name `analytics` is delayed-tolerant; do not share with mail-ingestion.
