# Production Readiness: Scanner, Scheduler, and Private Storage

Status: **not production-ready for ClamAV enablement**. The local environment has no reachable ClamAV daemon at the configured endpoint, so no real clean or infected verdict is claimed.

## Evidence table

| Component | Status | Evidence | Blocker/Risk | Required action |
|---|---|---|---|---|
| ClamAV | BLOCKED | `attachments:scanner-health` with `ATTACHMENT_SCANNER_BACKEND=clamav` reported `reachable=no`, `protocol=unavailable`, exit code `1`; integration suite failed both tests when `RUN_CLAMAV_TESTS=1` | Daemon unavailable at `127.0.0.1:3310` | Start an approved ClamAV service and rerun the Integration suite; do not enable production backend before it passes |
| Scheduler | PARTIAL | With explicit enable flags, `schedule:list` registered `logs:cleanup`, `inbound:cleanup`, and `inboxes:expire`; code uses `withoutOverlapping()` | A scheduler worker/process was not run in this audit | Deploy `php artisan schedule:work` or cron invoking `php artisan schedule:run` every minute and verify cache lock behavior |
| Private storage | PASS for tested contract | `platform:check` passed attachment storage checks; disk is outside web root with `visibility=private`; download and traversal regression tests passed | Shared multi-worker storage and production permissions were not proven | Configure shared private storage, verify permissions and symlink policy in deployment |
| Config cache | PASS | `config:cache`, `route:cache`, `event:cache`, and `config:clear` all exited `0` | Environment-specific secret/config deployment still requires operational verification | Restart queue workers after config deployment |
| Queue workers | PARTIAL | `platform:check` confirmed Redis queue configuration and named ingestion queue | No production worker process was observed | Run supervised workers and verify restart/failed-job alerts |

## Exact verification results

Scanner command with the required flags:

```text
backend: clamav
enabled: yes
connection_mode: tcp
reachable: no
protocol: unavailable
status: unavailable
exit code: 1
```

`php artisan test --testsuite=Integration --fail-on-skipped`:

```text
2 failed, 0 skipped
Reason: ClamAV unavailable at 127.0.0.1:3310
```

Focused unit/download/expiration regressions: **12 passed, 50 assertions**. The default unconfigured integration run has two explicit skips; those are not readiness evidence.

Scheduler with explicit enable flags registered:

```text
php artisan logs:cleanup --confirm
php artisan inbound:cleanup --confirm
php artisan inboxes:expire --confirm
```

`platform:check`: all foundation checks passed. No production attachment or database record was deleted or mutated.

## Required environment variables

Non-secret operational settings include:

```text
ATTACHMENT_SCANNER_BACKEND=disabled|clamav
ATTACHMENT_CLAMAV_HOST=<private host>
ATTACHMENT_CLAMAV_PORT=<private port>
ATTACHMENT_CLAMAV_SOCKET=<optional private socket>
ATTACHMENT_CLAMAV_CONNECT_TIMEOUT_SECONDS=<bounded value>
ATTACHMENT_CLAMAV_READ_TIMEOUT_SECONDS=<bounded value>
ATTACHMENT_SCAN_MAX_BYTES=<bounded value>
RUN_CLAMAV_TESTS=1                 # integration verification only
INBOX_EXPIRATION_SCHEDULER_ENABLED=false|true
INBOUND_RETENTION_CLEANUP_ENABLED=false|true
FILESYSTEM_ATTACHMENTS_DISK=attachments
```

No credentials or secret values belong in `.env.example`, logs, CI output, or this document.

## Deployment runbook

1. Start the approved private ClamAV daemon and verify TCP or Unix-socket reachability.
2. Run `php artisan attachments:scanner-health` and `--json`; require healthy status.
3. Run `RUN_CLAMAV_TESTS=1 php artisan test --testsuite=Integration --fail-on-skipped`.
4. Cache config/routes/events, deploy, then restart queue workers.
5. Run the scheduler using `php artisan schedule:work` under a supervisor, or invoke `php artisan schedule:run` every minute from cron.
6. Enable Inbox expiration/retention only after scheduler registration and lock-store checks pass.

## Emergency disable and rollback

- Set `ATTACHMENT_SCANNER_BACKEND=disabled`, clear/rebuild config cache, and restart workers. Disabled scanning must remain non-clean and downloads must stay blocked.
- Set `INBOX_EXPIRATION_SCHEDULER_ENABLED=false` and `INBOUND_RETENTION_CLEANUP_ENABLED=false`, clear config cache, and stop the scheduler worker.
- Do not delete quarantine files or database rows as an emergency action. Use the bounded retention workflow after incident review.
- Restore the previous application release and restart workers if a config or scanner deployment causes failures.

## Operational limitations

This audit did not prove real daemon execution, simultaneous scheduler lock contention, shared-storage permissions, symlink behavior on production storage, or MySQL/PostgreSQL concurrency. Local filesystem and SQLite tests are not production concurrency proof.

## Process readiness contract

Run `php artisan processes:health --json`. It reports queue connection,
workloads, worker and scheduler heartbeat freshness, failed jobs, backlog,
oldest queued-job age, worker limits and cache-lock compatibility. Missing or
stale heartbeats are `degraded`; job payloads, tokens, credentials, email
content and attachment bytes are never printed.

The local verification result was **degraded**: worker and scheduler
heartbeats were missing, the queue connection was `sync`, and the cache store
was `file`. `platform:check` passed local foundation checks, but this is not a
production worker-readiness PASS.

Use one canonical scheduler method in deployment: cron invoking
`php artisan schedule:run` every minute, or one supervised
`php artisan schedule:work` process. Do not deploy both. `withoutOverlapping`
requires a shared production-compatible cache store.

Linux Supervisor and Windows development examples:

```text
php artisan queue:work redis --queue=mail-ingestion,attachment-scanning,default --tries=3 --timeout=110 --sleep=3 --memory=512
php artisan schedule:work
```

Deployment order: cache config/routes/events, run `php artisan queue:restart`,
start workers, start exactly one scheduler, then run
`php artisan processes:health --json`. Rollback stops new processes, restores
the previous release, rebuilds caches and restarts workers. Emergency pause
stops workers or disables scheduler flags; it never purges queues or deletes
failed jobs. Alert on stale heartbeats, failed-job count, backlog and oldest
job age thresholds.
