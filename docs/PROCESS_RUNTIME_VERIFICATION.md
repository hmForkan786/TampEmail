# Isolated Process Runtime Verification

This profile is for local, non-production verification of Redis-backed worker registry and scheduler locking. It does not start anything automatically and does not replace the deployment runbook.

## Safety rules

- Never point this profile at production Redis, a production database, or a shared deployment network.
- Use a unique `CACHE_PREFIX`, queue name, `PROCESS_INSTANCE_ID`, and isolated Redis database index for every run.
- Keep `.env.process-verification` uncommitted and never print it with an environment dump.
- Do not use global Redis wipe commands, queue purges, or broad cache deletion. Remove only the disposable Compose service after all processes stop.
- Do not include full process IDs, instance IDs, credentials, connection strings, or raw environment values in evidence.
- All workers and the scheduler started for this run must be stopped before completion.

## Preconditions

Use a separate test database or disposable database schema. The Redis container is local-only and binds to `127.0.0.1:6389`; no production credential or network is used. Confirm Docker, PHP, the Laravel Redis client/extension, and the project dependencies are available. If these preconditions are absent, stop and report the blocker rather than substituting the default `sync` queue or `file` cache.

## Prepare the environment

From the project directory, copy the example to a local ignored file and fill only isolated values:

PowerShell:

```powershell
Copy-Item .env.process-verification.example .env.process-verification
$env:APP_ENV = 'local'
$env:COMPOSER_ALLOW_SUPERUSER = '0'
```

Use the project's approved environment-loading method to select `.env.process-verification`; do not overwrite `.env`. If the application cannot select a separate environment file without changing tracked code, run the verification in a disposable checkout/container instead.

## Native/WSL Redis Verification Path

Docker remains the preferred disposable path, but an isolated native Redis service is an accepted alternative when Docker is unavailable. Native Redis on Linux/macOS, Redis inside WSL2 on Windows, or an existing local Redis-compatible service may be used only when it is loopback-only and dedicated to this verification run.

Use one verified endpoint, not both Docker and native Redis. The example profile defaults to the Docker port `6389`; native/WSL Redis may use `6379`. Preflight accepts only these two approved loopback verification ports and reports only the safe mode (`docker` or `native`), never the endpoint or credentials. Do not weaken the isolation check or point it at shared organizational Redis.

Safe availability checks:

```bash
redis-cli -h 127.0.0.1 -p 6379 ping
```

On Windows with WSL2:

```powershell
wsl redis-cli -h 127.0.0.1 -p 6379 ping
```

Expected output is `PONG`. If `redis-cli` is missing, report a tooling blocker; do not install Redis through repository automation. Do not print authentication values. Use unique numeric Redis DB indexes, a unique queue name, and a unique cache prefix. Remove only verification-owned keys using an approved prefix-aware procedure after all processes stop; never use global wipe or unconditional database wipe commands.

Create the untracked `.env.process-verification`, set `REDIS_HOST=127.0.0.1` and `REDIS_PORT=6379` only for the native path, then run preflight before migrations, workers, or scheduler. Start exactly two workers, exactly one scheduler strategy, run health verification, verify registry/freshness/TTL, stop every process, and restore the original environment.

## Start and check isolated Redis

Before Docker, migrations, workers, or the scheduler, select the disposable verification environment and run the fail-closed preflight:

```bash
php artisan processes:verification-preflight
php artisan processes:verification-preflight --json
```

The preflight must pass. It checks Redis queue/cache selection, isolated names and database values, heartbeat thresholds, debug mode, and whether `.env.process-verification` is Git-tracked. A failure aborts the run; do not start services or migrations until the isolated values are corrected.

On Windows/XAMPP, do not overwrite the normal `.env`. Use a disposable checkout or a temporary environment-file selection mechanism provided by the local Laravel tooling. If a temporary `.env` swap is unavoidable, back up the original file byte-for-byte, keep the backup outside Git, restore it immediately after the run, and verify `git status --short`. Never perform this procedure against a production checkout.

```bash
docker compose -f docker-compose.process-verification.yml up -d
docker compose -f docker-compose.process-verification.yml ps
docker compose -f docker-compose.process-verification.yml exec temail-process-verification-redis redis-cli ping
```

Expected Redis response is `PONG`. The service has no persistent volume and is local-only. Do not use Redis-wide clearing commands.

## Configure Laravel safely

Select the verification environment, then clear only the application's own cached bootstrap artifacts in that disposable runtime:

```bash
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan event:cache
```

If schema is required, run migrations only against the isolated verification database after the preflight passes and the active connection is confirmed without printing credentials:

```bash
php artisan migrate --force
```

Check the effective safe driver fields:

```bash
php artisan about
php artisan schedule:list
```

The output must show Redis queue/cache. If it still shows `sync` or `file`, stop and follow the troubleshooting section; do not start workers.

## Start exactly two workers

Use two separate terminal sessions, the same isolated environment, and the same unique queue:

```bash
php artisan queue:work redis --queue=process-verification --sleep=3 --tries=3 --timeout=110 --backoff=5
```

Run the command in exactly two sessions. On Windows/XAMPP, use two PowerShell windows with the same project directory and verification environment. Use safe no-side-effect verification jobs only; do not dispatch mail, webhook, deletion, or production-domain work.

## Start exactly one scheduler strategy

Use one supervised-style scheduler process:

```bash
php artisan schedule:work
```

Do not also run cron `schedule:run` for this run. If controlled ticks are required instead, stop `schedule:work` first and execute `php artisan schedule:run` sequentially; never run both strategies concurrently.

## Verification scenarios

Run bounded checks without printing raw cache records:

```bash
php artisan processes:health --json
php artisan queue:failed
```

Expected successful evidence:

```text
Queue driver: redis
Cache store: redis
Active workers: 2
Scheduler: fresh
Overall health: healthy (or an explicitly explained threshold warning)
Raw process identifiers: absent
Failed jobs: 0 unless an intentional safe failure test is running
```

Confirm both workers are represented in readiness, then cause overlapping heartbeat writes through normal worker loops. Confirm no worker entry disappears, repeated writes do not duplicate an entry, and a lock/cache failure does not fail a safe job. Observe start, loop, success, failure, and graceful stop states with safe test jobs only.

Stop one worker and verify the remaining worker stays active. Wait for the configured `PROCESS_HEARTBEAT_TTL_SECONDS` boundary and confirm the stopped worker becomes stale/ignored while the active worker remains present. Verify the scheduler heartbeat refreshes, then stop the scheduler and confirm it becomes stale after the same configured freshness policy. Confirm scheduler overlap protection uses the shared lock store and does not permanently block a later tick.

Do not expose raw process IDs, instance IDs, cache keys, paths, credentials, serialized payloads, or stack traces in the report.

## Cleanup

1. Stop both queue workers with graceful termination and wait for both processes to exit.
2. Stop the one scheduler process and confirm no verification PHP processes remain.
3. Run `php artisan queue:failed` and capture only the bounded count/status.
4. Clear only the disposable application's cached bootstrap artifacts if needed.
5. Remove the isolated Redis service and network:

   ```bash
   docker compose -f docker-compose.process-verification.yml down --remove-orphans
   ```

6. Do not delete unrelated Redis keys or application data.
7. Confirm `.env.process-verification` is ignored/uncommitted and run `git status --short` and `git diff --check`.

## Troubleshooting

- **Redis unavailable:** confirm Docker health and local port `6389`; do not fall back to production or default Redis.
- **PHP Redis extension missing:** install/use the approved local dependency in the disposable environment; do not claim verification with `sync`.
- **Stale Laravel config:** run `optimize:clear`, select the verification environment, then rebuild config/route/event caches.
- **Queue reports `sync`:** the verification environment was not selected or cached configuration is stale; stop workers and correct it.
- **Cache reports `file`:** verify `CACHE_STORE=redis`, the Redis host/port, prefix, and cached configuration; stop before testing locks.
- **Scheduler heartbeat missing:** confirm exactly one scheduler is running, its environment is selected, and the heartbeat schedule is registered.
- **Lock unsupported:** use Redis or the approved database cache driver with shared storage; do not treat file/array locks as distributed proof.
- **Worker registry count incorrect:** verify both workers use the same isolated prefix and queue, confirm the expected worker count, and inspect only bounded health output. Do not dump cache keys or process identifiers.

## Static contract

This repository profile intentionally does not start Docker, workers, or a scheduler. Before any runtime attempt, validate only the Compose structure and repository diff:

```bash
docker compose -f docker-compose.process-verification.yml config
git diff --check
```

If Docker is unavailable, record that as a tooling limitation. A static pass is not live runtime readiness evidence.
