# ClamAV integration testing

This guide covers the disposable local test setup and the CI integration job. It does not enable ClamAV in production and must not contain production credentials or private service addresses.

## Default behavior

The configured attachment scanner backend defaults to `disabled`. A disabled or unavailable scanner never means that an attachment is clean: the attachment remains pending or follows the retry and terminal-failure lifecycle.

## Local test setup

Start the disposable test service:

```text
docker compose -f docker-compose.test.yml up -d clamav
```

The compose file exposes ClamAV on host port `13311`, mapped to the daemon's container port `3310`. Configure the test environment accordingly:

```text
RUN_CLAMAV_TESTS=1
ATTACHMENT_SCANNER_BACKEND=clamav
ATTACHMENT_CLAMAV_HOST=127.0.0.1
ATTACHMENT_CLAMAV_PORT=13311
```

Run the integration suite:

```text
php artisan test --testsuite=Integration --env=testing
```

Without `RUN_CLAMAV_TESTS=1`, the integration tests explicitly skip. When enabled, an unavailable daemon fails the test rather than producing a false pass. The suite uses harmless fixtures and the standard EICAR test string; it does not use real malware.

Check scanner readiness without scanning an attachment:

```text
php artisan attachments:scanner-health --json
```

The command returns a safe status such as `healthy`, `disabled`, `degraded`, or `failed`. It does not print attachment bytes, paths, scanner credentials, socket details, or exception traces.

## CI integration

The CI workflow starts the pinned `clamav/clamav:1.4.3` service on container port `3310`, publishes it as `127.0.0.1:3310`, waits for the `clamdscan --ping 1 --wait 2` health check, sets `RUN_CLAMAV_TESTS=1`, and runs:

```text
php artisan test --testsuite=Integration --fail-on-skipped --compact
```

CI therefore fails if the integration test is unexpectedly skipped or the service is unavailable.

## Scan outcomes and retries

Clean, infected, and permanent validation failures are terminal outcomes. Scanner-unavailable and transient transport outcomes remain retryable with bounded queue attempts and backoff. Infected attachments are never retried or overwritten as clean. After retry exhaustion, one deterministic failed transition is persisted; repeated execution does not emit another terminal event.

## Production enablement checklist

Before enabling a scanner in production:

1. Use an approved scanner deployment and network boundary.
2. Configure `ATTACHMENT_SCANNER_BACKEND` explicitly; do not rely on an accidental default.
3. Set bounded connect, read, scan, retry, and attachment-size limits.
4. Verify `attachments:scanner-health --json` from the intended runtime environment.
5. Confirm unavailable, infected, and permanent-failure paths in controlled tests.
6. Ensure logs and metrics contain no attachment content, credentials, or high-cardinality attachment identifiers.
7. Monitor retry exhaustion and quarantine/retention behavior before accepting production traffic.

Stop and remove the disposable local service after testing:

```text
docker compose -f docker-compose.test.yml down -v
```
