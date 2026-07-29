# Mail Server Pool Architecture (Prompt 652)

Application-side pool orchestration for **mail server inventory**. This is not an MTA, MX, or SMTP control plane.

**Status:** implemented  
**Boundary:** frozen with Prompt 651 (webhook inbound + external SMTP outbound)

## Frozen boundary

```text
MailProvider (label)
        ↓
MailServer Pool (inventory)
        ↓
Inbox assignment at create time
        ↓
Inbound Provider webhook / Outbound SMTP (unchanged)
        ↓
Application Queues
```

**Not included:** Postfix, Exim, Dovecot, native SMTP listener, direct MX receiver, Docker mail cluster.

## Pool model

```text
Pool (pool_key)
 ├── Server A  operational_status + health_score + capacity
 ├── Server B
 └── Server C
```

Each `MailServer` exposes:

| Field | Role |
| --- | --- |
| `pool_key` | Pool membership |
| `operational_status` | `active` \| `maintenance` \| `draining` \| `disabled` |
| `is_active` | Compatible flag; kept in sync with status transitions |
| `priority` | Tie-break after utilization / health |
| `max_inboxes` | Hard capacity for active inboxes (`null` = unlimited) |
| `max_throughput` | Optional operational throughput hint (monitoring) |
| `last_health_check_at` | Sidecar/operator heartbeat timestamp |
| `health_score` | Deterministic 0–100 score |
| `drain_started_at` | Set when entering `draining` |
| `consecutive_failures` | Failure strikes from ops/sidecar |

## Selection (deterministic)

1. Entitled/public pool keys resolved (`MailServerSelectionService` / `PUBLIC_MAIL_SERVER_POOL`).
2. Candidates: `pool_key` match, `operational_status=active`, `is_active=true`, fresh `last_health_check_at`, `health_score >= min`.
3. Row locks (`lockForUpdate`) then live active-inbox capacity check.
4. Rank:

```text
Healthy / eligible
        ↓
Lowest utilization
        ↓
Highest health_score
        ↓
Highest priority
        ↓
Lowest id (stable tie-break)
        ↓
Selected server
```

Random routing is not used.

## Capacity

Computed by `MailServerCapacityService`:

- `active_workload` — active, non-expired, non-deleted inboxes
- `remaining_capacity` — `max_inboxes - workload` (null when unlimited)
- `utilization` — ratio or null when unlimited
- `max_throughput` — optional hint; does **not** replace `max_inboxes` gating

All capacity decisions remain application-side at inbox create.

## Failover

Assignment-time only: if server A is draining/disabled/full/unhealthy, selection walks the ordered candidate list (bounded by `MAIL_SERVER_FAILOVER_MAX_CANDIDATES`) and chooses the next eligible peer.

- Bounded evaluations
- One inbox row per create transaction (no duplicate delivery)
- Existing inbox `mail_server_id` is **not** rewritten (preserve assignment)

## Maintenance & draining

```text
active → draining → (idle) → maintenance|disabled
accept new jobs = NO while draining/maintenance/disabled
finish existing inbox lifetimes = YES
```

No forced interruption of in-flight queue jobs. Drain completion is scheduled via `mail-servers:refresh-ha`.

## Configuration

See `config/mail_servers.php` and `.env.example` (`MAIL_SERVER_*`). No hardcoded production hostnames or secrets.

## Code map

| Component | Path |
| --- | --- |
| Status enum | `app/Enums/MailServerOperationalStatus.php` |
| Selection | `EloquentMailServerRepository::selectAvailableForPoolsForUpdate` |
| Health score | `MailServerHealthScorer` |
| Capacity | `MailServerCapacityService` |
| Transitions | `MailServerStatusTransitionService` |
| Heartbeat / failure | `MailServerHeartbeatService` |
| Monitor | `MailServerPoolMonitor` |
| Refresh | `MailServerHaRefreshService` |

Companions: [MAIL_SERVER_HIGH_AVAILABILITY.md](MAIL_SERVER_HIGH_AVAILABILITY.md), [MAIL_SERVER_OPERATIONS_RUNBOOK.md](MAIL_SERVER_OPERATIONS_RUNBOOK.md).
