# Outbound Mail Operations Runbook

Operational guide for outbound draft → delivery → provider events → reconciliation. See `OUTBOUND_MAIL_AUDIT.md` for audit results and `OUTBOUND_LAUNCH_RUNBOOK.md` for rollout/canary/emergency stop.

## Quick reference

| Command | Purpose |
| --- | --- |
| `php artisan outbound:status` | Ops snapshot (optionally `--json`) |
| `php artisan outbound:launch-readiness` | Launch readiness evaluation |
| `php artisan outbound:dispatch-scheduled` | Due scheduled → queued |
| `php artisan outbound:reconcile-stale-sending` | Stuck `sending` messages |
| `php artisan outbound:reconcile-unmatched-events` | Late provider correlation |
| `php artisan outbound:reconcile-events` | Event/attempt consistency |
| `php artisan outbound:reconcile-usage` | Usage reservation repair (dry-run default) |
| `php artisan outbound:prune --confirm` | Retention cleanup when enabled |
| `php artisan outbound:verify-domains` | Domain auth verification |
| `php artisan outbound:confirm-ses-subscription` | Confirm cached SNS subscription |
| `php artisan schedule:list` | Verify outbound schedule entries |

## Lifecycle overview

```text
Draft (editable, versioned)
  → Submit / Schedule / Direct send
  → queued (+ usage reserved)
  → DeliverOutboundMessageJob (outbound-delivery)
  → sending → transport accept → sent (+ usage commit)
  → verified provider event → delivered | failed | bounce/complaint
```

Terminal message states: `delivered`, `failed`, `cancelled`.

## Common failures

### Symptom: 403 / rollout blocked

- Check `OUTBOUND_ENABLED`, `OUTBOUND_ROLLOUT_MODE`, `OUTBOUND_EMERGENCY_STOP`
- Entitlements: `send_email` / `reply_email` / `forward_email`
- Domain `outbound_enabled` + SPF/DKIM verification
- Audit: `outbound.emergency_stop_blocked`, commercial denials

### Symptom: 422 recipient_suppressed

- Recipient on active suppression list (bounce/complaint/manual)
- Admin: Filament **Outbound Recipient Suppressions**
- Unsuppress only with elevated admin action; expires_at respected

### Symptom: Messages stuck in `queued`

- Workers on `outbound-delivery` not running
- Emergency stop releasing jobs without claiming
- Queue connection / Supervisor config

```bash
php artisan queue:work --queue=outbound-delivery
php artisan outbound:status
```

### Symptom: Stuck in `sending`

```bash
php artisan outbound:reconcile-stale-sending
```

- Never auto-requeues if transport was already invoked (ambiguous)
- Exhausted retry budget fails closed

### Symptom: Sent but never delivered

- Provider webhook workers on `outbound-events`
- Signature verification failures (invalid SNS/HMAC)
- Unmatched events:

```bash
php artisan outbound:reconcile-unmatched-events
php artisan outbound:reconcile-events
```

### Symptom: Usage / quota errors

```bash
php artisan outbound:reconcile-usage          # dry-run
php artisan outbound:reconcile-usage --confirm
```

- Retries after transport must not double-commit
- Cancel before transport releases reservation

### Symptom: Scheduled not firing

```bash
php artisan outbound:dispatch-scheduled
php artisan schedule:list
```

Requires minute scheduler (`schedule:run`) and `withoutOverlapping` lock free.

## Workers

| Workload | Queue name | Example Supervisor |
| --- | --- | --- |
| Delivery | `outbound-delivery` | `deploy/supervisor/temail-outbound-delivery-worker.conf.example` |
| Provider events | `outbound-events` | `deploy/supervisor/temail-outbound-events-worker.conf.example` |
| Maintenance | `outbound-maintenance` | `deploy/supervisor/temail-outbound-maintenance-worker.conf.example` |
| Notifications | `notifications` | `deploy/supervisor/temail-notification-worker.conf.example` |

Timeout ordering must remain: SMTP timeout < job timeout < queue `retry_after`.

## Emergency stop

```env
OUTBOUND_EMERGENCY_STOP=true
```

Or admin Launch Control override. Delivery jobs release without failing messages. Clear only after root cause is fixed.

## Monitoring (existing tooling)

- `outbound:status` / Filament Outbound Email Ops
- Launch metrics: bounce/complaint rates, queue age
- Cache counters for invalid signatures / duplicates
- Scheduler heartbeat via `processes:scheduler-heartbeat`

No separate monitoring platform required.

## Retention

```env
OUTBOUND_RETENTION_CLEANUP_ENABLED=true   # required for scheduled prune
```

```bash
php artisan outbound:prune              # dry-run / report
php artisan outbound:prune --confirm
```

Respects retention holds. Notification prune uses `OUTBOUND_NOTIFICATION_RETENTION_DAYS` (default 90).
