# Mail Server High Availability (Prompt 652)

HA for **mail server inventory pools** used at inbox assignment time. Complements Prompt 651 mail infrastructure audit.

## What “HA” means here

| Included | Excluded |
| --- | --- |
| Multi-server pools | Postfix/Exim clusters |
| Deterministic routing | Native SMTP HA |
| Health scoring | SMTP/MX probing |
| Maintenance / draining | Autoscaling / k8s |
| Assignment-time failover | Live inbox remigration |
| Ops commands + scheduler refresh | Infrastructure provisioning |

```text
Not included:
Automatic infrastructure provisioning
Autoscaling
Container orchestration
Load balancer provisioning
Native SMTP HA cluster
Cross-region replication
```

## Health scoring

`MailServerHealthScorer` (0–100), deterministic:

| Input | Effect |
| --- | --- |
| Non-`active` operational status | Score `0` (ineligible) |
| Active status | + `MAIL_SERVER_SCORE_ACTIVE_POINTS` (default 40) |
| Fresh `last_health_check_at` | + fresh points (default 40) |
| Zero consecutive failures | + zero-failure points (default 20) |
| Failure strikes | Subtract penalty × strikes |

Eligibility also requires `health_score >= MAIL_SERVER_MIN_HEALTH_SCORE` (default 50) and freshness within `MAIL_SERVER_HEALTH_WINDOW_MINUTES`.

Heartbeats/failures are reported by operators or sidecars (`mail-servers:ops heartbeat|failure`). The application **does not** open SMTP connections for scoring.

## Routing & failover sequence

```text
Pool keys
  → filter eligible Active servers
  → lock candidates
  → drop over-capacity / live-ineligible
  → sort utilization ↑, health ↓, priority ↓, id ↑
  → pick first
```

If the would-be primary is unavailable, the next ranked server is selected in the same transaction. Inbox create remains idempotent at the application layer (single insert).

## Queue integration

| Queue plane | Interaction |
| --- | --- |
| Inbound processing | Unaffected — webhook → `ProcessInboundMessageJob`; pool assignment already stamped on inbox |
| Outbound delivery | Unaffected — uses external SMTP, not `MailServer` rows |
| Failover | Must not re-dispatch duplicate inbox creates; selection is read/lock only |

## Scheduler

| Command | Cadence |
| --- | --- |
| `mail-servers:refresh-ha` | Every 5 minutes (`withoutOverlapping`) |

Refresh recomputes stored `health_score` and completes idle drains when `MAIL_SERVER_AUTO_COMPLETE_DRAIN=true`.

## Monitoring

```bash
php artisan mail-servers:pool-status --json
php artisan mail-servers:pool-status --pool=standard --json
```

Surfaces pool summaries, per-server status, health score, utilization, maintenance/drain timestamps, and recent failure counters. No external monitoring platform required.

## Security

- Owner/isolation for inbox APIs unchanged
- No SMTP credentials on `MailServer` (inventory only)
- Audit actions: `mail_server.status_changed`, `mail_server.heartbeat_recorded`, `mail_server.failure_recorded`, plus existing create/update audits
- CLI/ops output is non-secret JSON

## Acceptance summary

Deterministic pool management, health scoring, maintenance, draining, and assignment failover are implemented without breaking the Prompt 651 architectural boundary.
