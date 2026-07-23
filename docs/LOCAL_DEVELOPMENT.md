# Local development

This guide describes a safe repository-local setup. It does not make SQLite evidence of production relational behavior and does not enable ClamAV by default.

## Prerequisites

Install PHP 8.2+, Composer, a supported database driver, and Node.js/npm when frontend assets are needed. Redis is optional for the default local setup but required when `QUEUE_CONNECTION=redis` or `CACHE_STORE=redis` is selected.

## Repository setup

From the repository root:

```text
composer install
copy .env.example .env
php artisan key:generate
```

On Unix-like systems, use `cp .env.example .env`. Do not commit `.env` or generated test results.

## Database configuration and migration

Set the database values in `.env`. The example configuration uses MySQL defaults; a test environment may use SQLite. Run:

```text
php artisan migrate --force
```

Optional reviewed seed data can be loaded with `php artisan db:seed`. Do not use destructive refresh or reset commands against shared or production databases.

No storage link is required for the private attachment workflow; attachments must remain on private configured storage.

## Queue and cache

The example environment selects Redis for queue and cache. Start/configure Redis before using that selection, or choose a deliberately configured local database-backed alternative. Queue and lock behavior should match the selected `QUEUE_CONNECTION` and `CACHE_STORE`; an arbitrary local sync queue is not production evidence.

## Queue worker

The repository's local development script uses:

```text
php artisan queue:listen --tries=1 --timeout=0
```

Run it through `composer dev` when using the full local development process group. Production worker settings are different and are documented in [`PRODUCTION_RUNBOOK.md`](PRODUCTION_RUNBOOK.md).

## Scheduler

Run one local scheduler strategy when testing scheduled behavior:

```text
php artisan schedule:work
```

`php artisan schedule:run` is the documented cron alternative. Do not run both strategies unintentionally. Inspect scheduled tasks with `php artisan schedule:list` and readiness with `php artisan processes:health --json`.

## Signed inbound webhook

Set the provider-specific webhook configuration in `.env` using the documented timestamp skew, body-size, rate-limit, and signing-secret variables. The local endpoint is `POST /api/v1/inbound/webhook`; it is signed and does not use an API key. Use test fixtures and never place real provider secrets or message content in the repository.

## API keys and Filament administration

API keys are issued and managed through the supported authorized administrative/operator path, not a public API endpoint. Configure an approved Filament admin user and panel access according to the deployment's local seed/admin process. API keys still require their configured scopes and owner visibility; Filament access does not bypass those boundaries.

## ClamAV defaults and optional local setup

`ATTACHMENT_SCANNER_BACKEND` defaults to `disabled`. An unavailable scanner is never clean. For the disposable optional ClamAV setup, follow [`CLAMAV_INTEGRATION_TESTING.md`](CLAMAV_INTEGRATION_TESTING.md), which documents the test compose service, local port mapping, guarded tests, and cleanup. Do not point local configuration at production scanner services.

## Tests

Run the core suite with:

```text
php artisan test
```

Useful focused examples include:

```text
php artisan test --filter=InboundWebhook
php artisan test --filter=ScanInboundAttachment
php artisan test --filter=InboundMetrics
php artisan test --filter=AttachmentScannerHealth
```

ClamAV integration tests skip unless `RUN_CLAMAV_TESTS=1`; when enabled, an unavailable daemon fails rather than passing silently.

## Relational concurrency tests

Run the focused scenarios only with the required relational database and harness configuration:

```text
php artisan test --filter=RelationalInboxConcurrencyTest
php artisan test --filter=RelationalApiKeyConcurrencyTest
```

These tests may be explicitly environment-gated. SQLite execution alone does not prove production relational locking or concurrency guarantees.

## Health commands

```text
php artisan processes:health --json
php artisan inbound:health
php artisan attachments:scanner-health --json
```

Process health covers queue workers and scheduler heartbeats. Inbound health covers safe lifecycle metrics. Scanner health checks readiness without scanning an attachment.

## Common degraded states

- stale worker or scheduler heartbeat: inspect the supervised process and selected queue/cache stores;
- unavailable queue or incompatible lock store: verify database/Redis connectivity and configuration;
- inbound threshold breach: inspect safe counters, backlog, latency, and retry exhaustion;
- scanner disabled or unavailable: keep attachments pending and do not mark them clean;
- skipped ClamAV integration tests: confirm the guard and daemon availability before enabling them.

## Troubleshooting

Start with `git diff --check`, environment/configuration review, migration status, queue/cache connectivity, `schedule:list`, failed jobs, and the three health commands above. Review logs only for redacted operational data. Never paste bearer keys, webhook secrets, message bodies, raw headers, attachment bytes, or private service addresses into issues.

## Cleanup and reset warnings

Stop local workers and schedulers before changing configuration. Remove only disposable local containers/volumes through the documented ClamAV guide. Database refresh/reset commands can destroy data and are not part of the normal setup; use them only against an explicitly disposable database after review.

## Related documentation

- [`README.md`](../README.md) — product overview and command index
- [`API_REFERENCE.md`](API_REFERENCE.md) — endpoint contract
- [`API_CONVENTION.md`](API_CONVENTION.md) — shared API protocol
- [`ARCHITECTURE.md`](ARCHITECTURE.md) — system boundaries and layers
- [`CLAMAV_INTEGRATION_TESTING.md`](CLAMAV_INTEGRATION_TESTING.md) — optional scanner tests
- [`PRODUCTION_RUNBOOK.md`](PRODUCTION_RUNBOOK.md) — deployment operations
