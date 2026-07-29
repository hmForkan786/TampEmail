# Mail Infrastructure Operations Runbook

Operational procedures for production mail under the **app-orchestrated** boundary (Prompt 651). Companion to [MAIL_INFRASTRUCTURE_PRODUCTION_AUDIT.md](MAIL_INFRASTRUCTURE_PRODUCTION_AUDIT.md).

## Architecture reminder

```text
Provider → signed inbound webhook → queue → parse/resolve/ingest → inbox
App → outbound queue → external SMTP → recipient
```

Native SMTP/LMTP/MX inbound is **not** available. Do not attempt to “fix” mail by installing Postfix into this application host as part of normal Temail ops — that is out of architecture.

## Workers & queues

| Workload | Env | Default queue |
| --- | --- | --- |
| Inbound processing | (job uses connection default unless remapped) | `default` (typical) |
| Attachment scanning | `QUEUE_ATTACHMENT_SCANNING` | `attachment-scanning` |
| Outbound delivery | `QUEUE_OUTBOUND_DELIVERY` | `outbound-delivery` |
| Outbound provider events | `QUEUE_OUTBOUND_EVENTS` | `outbound-events` |
| Outbound maintenance | `QUEUE_OUTBOUND_MAINTENANCE` | `outbound-maintenance` |
| Notifications | `QUEUE_NOTIFICATIONS` | `notifications` |
| Billing (reference) | `BILLING_QUEUE_*` | `default` |

Example worker shapes (replace placeholders for the deployment):

```bash
php artisan queue:work redis --queue=default,attachment-scanning --tries=3 --timeout=110
php artisan queue:work redis --queue=outbound-delivery --tries=3 --timeout=60
php artisan queue:work redis --queue=outbound-events --tries=3 --timeout=60
php artisan queue:work redis --queue=outbound-maintenance --tries=3 --timeout=60
php artisan queue:work redis --queue=notifications --tries=3 --timeout=60
```

Use `deploy/supervisor/temail-*.conf.example` templates. Keep outbound queues off the generic inbound worker when isolating load (see comments in those examples).

### Queue restart

1. Gracefully stop Supervisor/systemd worker programs (`stopsignal=TERM`, wait for `stopwaitsecs`).
2. Confirm no stuck `sending` outbound rows older than threshold (or run stale-sending reconcile after warm-up).
3. Start workers; verify `php artisan processes:health`.
4. Spot-check `inbound:health` and `outbound:status` / launch readiness.

Do **not** `queue:flush` production queues to clear backlogs.

## Scheduler

Exactly one strategy: supervised `schedule:work` **or** cron `schedule:run` every minute — never both.

Mail-relevant schedules (see `php artisan schedule:list`):

| Cadence | Command |
| --- | --- |
| Hourly | `outbound:verify-domains` |
| Every minute | `outbound:dispatch-scheduled` |
| Every 5 / 15 minutes | outbound reconcile commands |
| Daily (when enabled) | `inbound:cleanup`, `outbound:prune`, `inboxes:expire` |

## Health verification

```bash
php artisan inbound:health
php artisan outbound:launch-readiness --json
php artisan outbound:status
php artisan processes:health --json
php artisan processes:runtime-smoke --json
php artisan attachments:scanner-health   # if ClamAV enabled
php artisan billing:stripe-health        # if Stripe enabled (sanity)
```

Treat `sync` queue connection, stale heartbeats, unbounded backlog, and launch-readiness `blocked` as actionable.

## Webhook replay (inbound)

Authorized **platform admin** path only: Filament / admin actions using `ReplayInboundFailureAction`.

1. Confirm failure reason and that raw MIME retention still allows replay.
2. Replay one item; monitor `inbound:health` counters (`replayed`, `failed`).
3. Do not forge provider webhooks with production secrets from laptops as a routine fix — use the audited replay path.

## Provider outage

### Inbound provider unavailable

1. Application continues; backlog will not grow if providers cannot POST.
2. When provider recovers, expect burst — watch rate limits (`INBOUND_WEBHOOK_RATE_LIMIT_PER_MINUTE`) and queue depth.
3. Coordinate provider-side retries; app dedupes on `message_id` / provider message id.

### Outbound SMTP / provider unavailable

1. Leave `OUTBOUND_EMERGENCY_STOP=true` or pause rollout if volume is harmful.
2. Failed jobs retry with backoff; inspect `failed_jobs` and outbound failure codes.
3. After SMTP recovery: clear emergency stop only with launch readiness green; optionally canary (`outbound:canary-send` — explicit opt-in).
4. Stale `sending` rows: `outbound:reconcile-stale-sending` (scheduled).

## Retry procedures

| Situation | Action |
| --- | --- |
| Inbound job failed after tries | Inspect inbound failure record; admin replay if raw retained |
| Outbound delivery failed (retryable) | Job backoff; manual retry only via supported product/admin paths |
| Provider event unmatched | Scheduled reconcile; do not hand-edit provider message ids |
| Attachment scan failed | Attachment scan retry / scanner health |

## Maintenance mode

1. Enable Laravel maintenance (`php artisan down`) for application freeze when required.
2. For outbound-only hold: set `OUTBOUND_EMERGENCY_STOP=true` and/or `OUTBOUND_ROLLOUT_MODE=disabled` without taking the whole site down.
3. Drain or pause workers only with an approved change window; document who paused what.

## Incident response (short)

1. **Identify plane:** inbound webhook, outbound SMTP, queues, DNS auth, attachments, billing coupling.
2. **Evidence:** health command JSON, recent `AuditLog` actions, failed job ids (no secrets).
3. **Contain:** emergency stop / maintenance / disable provider posting at edge.
4. **Recover:** restore workers/scheduler; reconcile; replay only via audited paths.
5. **Postmortem:** note whether deferred MTA assumptions were incorrectly introduced during the incident.

## Backup & restore (mail-adjacent)

Follow platform backup for database + private attachment disks. After restore:

1. `php artisan migrate --force` only if schema must catch up under change control.
2. Restart workers/scheduler.
3. Run process + inbound + outbound readiness checks.
4. Do not replay all historical provider webhooks blindly — prefer failure/reconcile tooling.

See also `php artisan backup:restore-health` where available and `docs/PRODUCTION_RUNBOOK.md`.
