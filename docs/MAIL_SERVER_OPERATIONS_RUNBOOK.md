# Mail Server Operations Runbook (Prompt 652)

Operational procedures for mail server **pool inventory** HA. Companion to [MAIL_SERVER_POOL_ARCHITECTURE.md](MAIL_SERVER_POOL_ARCHITECTURE.md) and [MAIL_SERVER_HIGH_AVAILABILITY.md](MAIL_SERVER_HIGH_AVAILABILITY.md).

## Preconditions

- Prompt 651 boundary remains: inbound signed webhook; outbound external SMTP.
- `MailServer` rows are inventory for inbox assignment — not MTA hosts to SSH into for Postfix.

## Health verification

```bash
php artisan mail-servers:pool-status --json
php artisan mail-servers:refresh-ha
php artisan schedule:list   # confirm mail-servers:refresh-ha
```

Eligible count should be > 0 for each production pool (`PUBLIC_MAIL_SERVER_POOL` and entitled pools).

## Take a server offline (graceful)

1. Prefer **drain** so existing inboxes keep their assignment:

```bash
php artisan mail-servers:ops transition <uuid> draining --reason=planned_maintenance
```

2. Confirm no new assignments:

```bash
php artisan mail-servers:pool-status --pool=<pool_key> --json
```

3. Wait until active workload is 0 (inbox expiry/delete). Scheduler auto-completes drain → `maintenance` (configurable).

4. Or force maintenance immediately (still blocks new work; does not delete inboxes):

```bash
php artisan mail-servers:ops transition <uuid> maintenance --reason=immediate
```

## Return a server online

1. Ensure sidecar/operator connectivity for the *real* edge mail provider is healthy (outside this app).
2. Record a heartbeat:

```bash
php artisan mail-servers:ops heartbeat <uuid>
```

3. Transition to active:

```bash
php artisan mail-servers:ops transition <uuid> active --reason=returned
```

4. Verify `eligible_for_assignment=true` in pool status.

## Draining checklist

```text
accept new jobs = NO
finish existing jobs/inboxes = YES
mark idle → maintenance (scheduler)
```

Do not force-delete in-flight queue jobs related to those inboxes.

## Emergency failover

1. Mark the failing inventory row `draining` or `disabled`.
2. Confirm peer servers remain eligible and under capacity.
3. Optional: record failure strikes for visibility:

```bash
php artisan mail-servers:ops failure <uuid> --reason=provider_outage
```

4. New inbox creates fail over automatically via selection — no inbox remigration job.

## Capacity exhaustion

Symptoms: `EligibleMailServerUnavailableException` / failed anonymous or entitled provisioning.

Actions:

1. Raise `max_inboxes` on healthy peers or add another active inventory row to the pool.
2. Drain or disable unhealthy peers.
3. Confirm heartbeats keep scores above `MAIL_SERVER_MIN_HEALTH_SCORE`.

## Provider outage

If the **inbound mail provider** (webhook feeder) is down, pool HA cannot receive mail — restore the provider path (Prompt 651 ops). Pool status only governs **which inventory** receives new inbox assignments.

If **outbound SMTP** is down, use outbound emergency stop / launch controls — unrelated to `MailServer` pools.

## Heartbeat contract

Sidecars should call (or operators run) `heartbeat` after a successful readiness check **against the real edge**, not against Laravel. Failures should increment strikes via `failure` so routing deprioritizes the row.

## Audit

Inspect `audit_logs` for:

- `mail_server.status_changed`
- `mail_server.heartbeat_recorded`
- `mail_server.failure_recorded`
- `mail_server.created` / `mail_server.updated`
