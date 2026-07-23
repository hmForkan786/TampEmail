# Process Operations Runbook

This runbook describes an environment-neutral deployment contract for queue workers and the Laravel scheduler. Replace angle-bracket placeholders in the Supervisor examples; do not place credentials, tokens, passwords, private hostnames, or connection strings in those files.

## Required production configuration

- Use a durable queue connection such as Redis or database. `sync` runs work inside the HTTP process and is not worker-readiness evidence.
- Use a shared, atomic cache/lock store such as Redis or a supported database cache. A local `file` cache is not proof of distributed scheduler overlap protection.
- Set the queue connection's `retry_after` above the worker `--timeout` with enough margin for cleanup and retry scheduling.
- Configure bounded `--sleep`, `--tries`, `--timeout`, `--backoff`, memory, and worker count through deployment configuration.
- Keep `PROCESS_HEARTBEAT_TTL_SECONDS` and worker/scheduler freshness expectations aligned. A heartbeat older than the configured TTL is degraded/stale.
- Decide one canonical application and scheduler timezone. The current local environment reports UTC; production teams must explicitly choose and document the deployment timezone.
- Keep secrets in the environment or secret manager. They must not appear in templates, logs, health output, or this runbook.

## Supervisor installation and first start

1. Install Supervisor using the operating system's approved package/image process.
2. Copy `deploy/supervisor/temail-worker.conf.example` and `temail-scheduler.conf.example` into the Supervisor configuration directory.
3. Replace `<PROJECT_PATH>`, `<PHP_BINARY>`, `<DEPLOYMENT_USER>`, queue values, worker limits, log paths, and bounded stop/log values.
4. Ensure the deployment user can read the release and write only the intended storage/log directories.
5. Validate the release before starting processes:

   ```bash
   php artisan config:cache
   php artisan route:cache
   php artisan event:cache
   php artisan schedule:list
   ```

6. Reload Supervisor configuration, then start the worker group and exactly one scheduler strategy for the intended topology.
7. Verify with `php artisan processes:health --json`.

Do not start both `schedule:work` and a cron `schedule:run` for the same application topology unless that duplication is deliberately coordinated and its lock behavior is understood.

## Cron alternative

Instead of the supervised scheduler program, a deployment may use one cron entry:

```cron
* * * * * <PHP_BINARY> <PROJECT_PATH>/artisan schedule:run >> <LOG_DIRECTORY>/scheduler-cron.log 2>&1
```

Use the deployment user's crontab, substitute real paths only in the deployment system, and ensure the cron invocation and `schedule:work` are not both active unintentionally.

## Deployment and restart order

1. Deploy the new release and validate configuration without printing the environment.
2. Build config, route, and event caches.
3. Run `php artisan queue:restart` so existing workers finish their current job and reload the release.
4. Reload/restart Supervisor-managed workers gracefully; do not kill jobs or purge queues.
5. Start or reload the single scheduler process/cron strategy.
6. Run `php artisan processes:health --json`, inspect `queue:failed`, and confirm fresh worker and scheduler heartbeats.

`healthy` means thresholds and freshness checks pass. `degraded` means the command is safe to run but has stale heartbeats, incompatible infrastructure, or threshold warnings. `failed` means a readiness dependency/reporting operation is unavailable or malformed. Do not treat a local `sync` queue or `file` cache as healthy production evidence.

## Graceful stop and rollback

Supervisor should send `TERM`, keep process groups together, and allow the bounded `stopwaitsecs` value in the template for active jobs to finish. During an emergency pause, stop new workers or disable optional scheduled cleanup flags; do not purge queues, delete failed jobs, or delete attachment files as a first response.

For rollback:

1. Stop or pause new process starts.
2. Deploy the previously approved release.
3. Rebuild config, route, and event caches.
4. Run `php artisan queue:restart` and gracefully reload workers.
5. Restart exactly one scheduler strategy.
6. Verify health, failed jobs, and application logs before resuming traffic.

## Failed jobs, logs, and monitoring

Use:

```bash
php artisan queue:failed
php artisan processes:health --json
```

Review a failed job through the approved incident workflow, correct the underlying issue, and use the documented `queue:retry` policy for that deployment. Never paste serialized payloads, authorization values, email content, or secrets into tickets or logs.

Log rotation, retention, access control, and shipping are deployment-platform responsibilities. Configure rotation for both worker and scheduler stdout/stderr paths, bound file size/backups, and alert on repeated restarts, failed-job growth, queue backlog, oldest-job age, and stale heartbeats.

## Multi-instance considerations

All application instances must use the same compatible queue/cache/lock backend and a collision-safe cache prefix. Worker identities are per process; scheduler overlap locks require shared atomic storage. Validate worker count, heartbeat freshness, lock behavior, and oldest-job age from the deployed topology. Local SQLite, file cache, or a single developer process does not prove relational/distributed concurrency.

## Safe verification commands

Run these read-only checks after deployment; they do not start a worker or scheduler:

```bash
php artisan schedule:list
php artisan processes:health --json
php artisan queue:failed
php artisan about
git diff --check
```

Never include raw environment output, credentials, process identifiers, cache keys, filesystem secrets, or serialized queue payloads in the verification record.
