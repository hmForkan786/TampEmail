# Outbound Worker Deployment (Prompt 613)

This document hardens outbound queue deployment, worker isolation, and
scheduler readiness on top of the architecture in
`docs/OUTBOUND_EMAIL_CONTRACT.md` and the generic contract in
`docs/PROCESS_OPERATIONS.md`. Read both first; this document only covers
outbound-specific topology, timeouts, reconciliation, and heartbeats.

## Queue topology

Three explicit, isolated queues (never combined with each other or with
attachment scanning):

| Queue (env) | Workload | Carries |
|---|---|---|
| `outbound-delivery` (`QUEUE_OUTBOUND_DELIVERY`) | `DeliverOutboundMessageJob` | SMTP/provider submission only |
| `outbound-events` (`QUEUE_OUTBOUND_EVENTS`) | `ProcessOutboundProviderEventJob` | Provider webhook ingestion only |
| `outbound-maintenance` (`QUEUE_OUTBOUND_MAINTENANCE`) | Reserved for future async maintenance jobs | Domain-auth batches, suppression/abuse review actions if they move off the scheduler |

`attachment-scanning` (inbound) remains a separate queue and must never share
a `--queue` list or worker program with any outbound queue: a ClamAV backlog
must not delay outbound delivery, and a provider outage must not delay
attachment scans.

`App\Services\Outbound\OutboundQueueReadinessService` validates this topology
(`queue.workloads.outbound_delivery` / `outbound_events` / `outbound_maintenance`
must all be non-empty, mutually distinct, and distinct from
`attachment_scanning`) and reports `invalid_queue_topology` (readiness
`failed`) otherwise. Inspect it via `php artisan outbound:status --json`
under the `queue` key.

### Why maintenance work runs from the scheduler today

Domain verification (`outbound:verify-domains`) and stale-sending
reconciliation (`outbound:reconcile-stale-sending`) execute synchronously
inside the scheduler process, matching every other maintenance command in
this project (`logs:cleanup`, `inbound:cleanup`, `inboxes:expire`). They are
not dispatched as queued jobs today, so `outbound-maintenance` currently has
no traffic. The queue name and a dedicated worker template are still defined
so that if a maintenance operation becomes heavy enough to need queued/async
execution, it can be dispatched onto `outbound-maintenance` without any
topology change. Recipient suppression and abuse-block expiry are evaluated
dynamically at query time (`expires_at` checks in
`OutboundRecipientSuppression::isCurrentlyActive()` and
`OutboundAbuseBlock::isActive()`), so no separate expiry job is required for
correctness.

## Worker configuration (Supervisor)

Copy and edit placeholders in:

- `deploy/supervisor/temail-outbound-delivery-worker.conf.example`
- `deploy/supervisor/temail-outbound-events-worker.conf.example`
- `deploy/supervisor/temail-outbound-maintenance-worker.conf.example` (reserved; safe to run against an always-empty queue today)

Each program is isolated (its own Supervisor `[program:]` block, its own log
files, its own `numprocs`), so a worker crash loop or backlog in one queue
never starves another. All three follow `docs/PROCESS_OPERATIONS.md`
conventions: bounded `--sleep` / `--tries` / `--timeout` / `--backoff` /
`--memory`, `stopasgroup`/`killasgroup` with a bounded `stopwaitsecs` for
graceful `TERM` restarts, `autorestart=true`, and no secrets in the command
line or environment-specific values baked into the template. Worker counts
are configurable via `numprocs=<OUTBOUND_*_WORKER_COUNT>`, sourced from:

```env
OUTBOUND_DELIVERY_WORKER_COUNT=1
OUTBOUND_EVENTS_WORKER_COUNT=1
OUTBOUND_MAINTENANCE_WORKER_COUNT=1
OUTBOUND_WORKER_TIMEOUT_SECONDS=60
OUTBOUND_WORKER_SLEEP_SECONDS=3
OUTBOUND_WORKER_TRIES=3
OUTBOUND_WORKER_BACKOFF_SECONDS=30
OUTBOUND_WORKER_MEMORY_MB=512
```

`DeliverOutboundMessageJob` and `ProcessOutboundProviderEventJob` are queued
onto `outbound-delivery`/`outbound-events` from their constructors
(`onQueue(...)`), independent of the generic worker's `--queue` list, so they
cannot accidentally run on the shared inbound/attachment worker.

The generic `temail-worker.conf.example` (inbound + attachment scanning +
default) is unchanged and must not list any outbound queue.

## Timeout alignment

Required ordering, smallest to largest:

```text
OUTBOUND_SMTP_TIMEOUT        (default 30s)  transport-level socket timeout
  <
OUTBOUND_WORKER_TIMEOUT_SECONDS (default 60s)  queue:work --timeout for outbound workers
  <
queue connection retry_after (>= 90s; REDIS_QUEUE_RETRY_AFTER=120, DB_QUEUE_RETRY_AFTER=90)
```

If the job timeout does not exceed the transport timeout, a hung socket can
outlive the job's own timeout guard. If `retry_after` does not exceed the job
timeout with margin, Laravel's queue can release the job for a second
concurrent attempt before the first worker has finished, risking a duplicate
send.

`App\Services\Outbound\OutboundWorkerConfigValidator` checks this ordering
(plus a `retry_after >= 90` floor) without sending mail. It never returns
secrets — only booleans, seconds, and the connection name. It feeds
`OutboundQueueReadinessService`, which reports `invalid_worker_timeout_config`
(readiness `failed`) when misconfigured. Inspect via
`php artisan outbound:status --json` → `queue.worker_config`.

## Stale sending reconciliation

A message can get stuck in `sending` only if its delivery worker died
mid-attempt (crash, OOM kill, forced restart, host failure) — the atomic
`queued → sending` claim in `DeliverOutboundMessageJob` means a normal retry
never re-processes an already-`sending` row.

`outbound_messages.transport_attempted_at` is reset to `null` at claim time
and set immediately before `OutboundTransportInterface::send()` is called.
This is the safety signal:

- **`transport_attempted_at` is `null`** — the worker died before ever
  submitting to the transport (e.g. during authorization or attachment
  re-validation). No duplicate-send risk: safely requeue
  (`state → queued`, re-dispatch `DeliverOutboundMessageJob`), or mark
  `failed` with `stale_sending_attempts_exhausted` if the attempt budget is
  already spent.
- **`transport_attempted_at` is set** — the transport call was made and the
  worker died before the result could be persisted. The outcome is
  **ambiguous**: the provider may have already accepted (or even delivered)
  the message. The message is left in `sending` and flagged
  (`reconciliation_flagged_at`, `reconciliation_note=ambiguous_transport_outcome`)
  for manual review. **It is never automatically failed or resent.** A later
  provider event (`sent`/`delivered`/bounce) or an explicit admin action is
  the only way to resolve it.

Run/inspect:

```bash
php artisan outbound:reconcile-stale-sending --limit=50
```

Configuration:

```env
OUTBOUND_STALE_SENDING_THRESHOLD_SECONDS=900
OUTBOUND_STALE_SENDING_BATCH_SIZE=50
```

The command holds a 300s cache lock (`outbound:reconcile-stale-sending`) so
overlapping scheduler runs are inert, and only ever evaluates a bounded
batch per run. Audit events: `outbound.stale_sending_requeued`,
`outbound.stale_sending_failed_exhausted`,
`outbound.stale_sending_flagged_ambiguous` — safe metadata only (ids,
attempt counts, elapsed seconds; never bodies or recipients).

## Unmatched provider-event reconciliation

A provider webhook can theoretically arrive before
`DeliverOutboundMessageJob` persists `provider_message_id` (isolated workers
mean the events worker and delivery worker are independent processes).
`outbound:reconcile-unmatched-events` retries correlation for
`outbound_provider_events` rows with no `outbound_message_id` inside a
bounded recent window, using the same matching rules as live ingestion
(ambiguous matches are still never applied). Already-matched events and
events outside the window are never re-evaluated.

```bash
php artisan outbound:reconcile-unmatched-events --limit=50
```

```env
OUTBOUND_UNMATCHED_EVENT_WINDOW_HOURS=24
OUTBOUND_UNMATCHED_EVENT_BATCH_SIZE=50
```

Audit event: `outbound.provider_event_reconciled`.

## Scheduler

Registered in `bootstrap/app.php`, each with `withoutOverlapping()`:

| Command | Cadence | Purpose |
|---|---|---|
| `processes:scheduler-heartbeat` | every minute | Scheduler liveness (existing) |
| `outbound:verify-domains` | hourly | SPF/DKIM/DMARC readiness (existing) |
| `outbound:reconcile-stale-sending` | every 5 minutes | Stale `sending` requeue/flag |
| `outbound:reconcile-unmatched-events` | every 15 minutes | Unmatched provider-event retry |
| `outbound:reconcile-events` | every 15 minutes | Full orchestration: unmatched + out-of-order + attempt backfill + impossible-state flags |

`outbound:reconcile-events` composes the unmatched/out-of-order paths with delivery-attempt repair and impossible-state detection. It never auto-resends ambiguous messages. See Prompt 614 section in `docs/OUTBOUND_EMAIL_CONTRACT.md` for timeline and precedence.

All outbound maintenance commands are bounded (explicit `--limit`, default
from config), take a short-lived cache lock, and never log message bodies,
recipients, or attachment content. Only ids, states, counts, and elapsed
seconds are audited or printed.

## Heartbeats / readiness

`App\Services\Outbound\OutboundQueueReadinessService` (backed by the
existing `App\Services\Ops\ProcessHeartbeatWriter`) reports, per outbound
queue:

- **Delivery worker** — freshness of worker heartbeats whose `queue_names`
  include `outbound-delivery` (`OUTBOUND_DELIVERY_WORKER_COUNT` expected).
- **Provider-event worker** — same, for `outbound-events`
  (`OUTBOUND_EVENTS_WORKER_COUNT` expected).
- **Maintenance scheduler** — freshness of the existing scheduler heartbeat
  (`processes:scheduler-heartbeat`), since maintenance commands run inline
  from the scheduler rather than a dedicated worker today.
- Per-queue backlog (`jobs` table count), oldest-job age, and failed-job
  count for `outbound-delivery` and `outbound-events`.

Missing required workers (expected count > 0, zero fresh heartbeats) or a
stale maintenance scheduler heartbeat report as `degraded`. Invalid queue
topology or invalid worker timeout configuration report as `failed`
(fail-closed). This is additive to, and does not replace, the generic
`php artisan processes:health --json` aggregate report, which still covers
overall queue connection, cache/lock-store compatibility, and total
backlog/failed-job counts across every queue.

```bash
php artisan outbound:status --json   # readiness + queue (topology, worker_config, delivery, events, maintenance)
php artisan processes:health --json  # generic aggregate worker/scheduler readiness
```

## Failed-job workflow

- `failed_jobs` rows are terminal Laravel queue failures (attempts
  exhausted or an uncaught exception). For outbound jobs this only happens
  for bugs/infra failures — normal retryable transport failures are handled
  inside `DeliverOutboundMessageJob` (`tries()`/`backoff()`) without ever
  reaching `failed_jobs`.
- **Never run a blanket `php artisan queue:retry all`** for outbound queues.
  A raw `queue:retry` replay bypasses authorization, entitlement,
  suppression, and domain-authentication re-checks — it only re-runs the
  atomic `queued → sending` claim, which is safe (a no-op) for already
  `sent`/`delivered`/`cancelled` messages, but does not represent an
  authorized retry decision for genuinely `failed` messages.
- For a `failed` `OutboundMessage`, use the authorized API workflow instead:
  `POST /api/v1/outbound-messages/{id}/retry` (`outbound_messages:write`),
  which re-validates entitlements, domain authentication, and attachment
  safety before moving the message back to `queued`
  (`App\Actions\Outbound\RetryOutboundMessageAction`). Only failure
  categories that are safe to retry should be retried by an operator; do not
  retry permanent rejections (invalid recipient, unauthorized sender, unsafe
  attachment).
- Rows flagged `reconciliation_note=ambiguous_transport_outcome` are not in
  `failed_jobs` and are not `failed` messages — they remain `sending`
  pending a provider event or explicit admin reconciliation. Do not resend
  or retry them from `queue:retry`; requeueing an ambiguous message risks a
  duplicate send to the recipient.
- Never paste job payloads, recipient addresses, message bodies, or
  credentials into incident tickets or logs (see
  `docs/PROCESS_OPERATIONS.md`).

## Verification

```bash
vendor/bin/pint --dirty
php artisan test --filter=OutboundQueue
php artisan test --filter=ProcessHealth
php artisan test --filter=Scheduler
php artisan test --filter=Outbound
php artisan test
php artisan schedule:list
php artisan outbound:status --json
```

`php artisan queue:monitor` is Laravel's built-in threshold alert command
(`queue:monitor outbound-delivery,outbound-events,outbound-maintenance:100`);
it is not a project-specific command and requires no additional code here.
Wire it into the deployment platform's alerting if desired.
