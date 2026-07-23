# Temail

Temail is a self-hosted temporary-email platform for owner-scoped inboxes, inbound webhook processing, attachment handling, operational metrics, and Filament administration.

## Product overview

The current inbound boundary is a signed provider webhook. Messages are queued for asynchronous processing, stored against an owned inbox, and exposed through the owner API or administrator tools. Native SMTP/LMTP ingress is not implemented.

## Architecture and stack

- PHP 8.2+ and Laravel 12
- MySQL or another supported Laravel database driver; SQLite is useful for local tests
- Database-backed queue by default, with Redis supported by configuration
- Database cache by default, with Redis supported for process coordination
- Filament 5 administration
- Pest/PHPUnit feature, unit, integration, and relational-concurrency tests
- Optional ClamAV attachment scanning, disabled by default

## Implemented capabilities

- Owner-scoped inbox, email, attachment, read-state, and mail-server API operations
- API-key authentication with explicit read/write scopes and rate limiting
- Signed, provider-neutral inbound webhook with bounded request validation
- Asynchronous inbound processing and safe lifecycle/health metrics
- Attachment scanning with clean, infected, retryable, and terminal-failure outcomes
- Filament administration for operational inbound failures and related resources
- Queue-worker and scheduler heartbeat/readiness reporting
- Retention, expiration, audit, and request-log controls

## Explicitly deferred or unavailable

- Native SMTP/LMTP ingress is not implemented; the signed webhook is the inbound boundary.
- Public API-key issuance is not exposed through the public API; administrative flows are separate.
- Customer billing and subscription APIs are not exposed.
- Raw-MIME replay and arbitrary SMTP management endpoints are not exposed.
- ClamAV is disabled unless explicitly configured and approved.

## Requirements

Install PHP 8.2 or newer with the extensions required by Laravel 12, Composer, a database, and Node.js/npm if frontend assets are built. Queue workers and one scheduler process are operational requirements for asynchronous processing and scheduled maintenance. Anonymous or scheduled features may be feature-flagged and fail closed.

## Local installation

```text
composer install
copy .env.example .env
php artisan key:generate
php artisan migrate --force
npm install
npm run build
```

On Unix-like systems, use `cp .env.example .env` instead of `copy`. The repository also provides `composer setup` for the standard setup sequence.

## Environment configuration

Copy `.env.example` and set the application URL, database connection, queue connection, cache store, mail settings, and provider webhook configuration for the environment. Keep secrets only in environment or approved secret-manager storage. Do not commit `.env`, API keys, webhook secrets, scanner credentials, or private service addresses.

The default queue connection and cache store are database-backed. Redis can be selected when the deployment provides it. Configure `ATTACHMENT_SCANNER_BACKEND=clamav` only after the ClamAV enablement gate in the integration guide and production runbook is satisfied.

## Database migration and seeding

Run migrations with:

```text
php artisan migrate --force
```

Use `php artisan db:seed` only when the deployment has reviewed the configured seeders. Never run destructive refresh commands against production data.

## Queue worker operation

Run the configured worker using the deployment's queue supervisor. For local development, the repository's `composer dev` script starts `php artisan queue:listen --tries=1 --timeout=0`. Production workers must use bounded process settings, restart supervision, and the configured queue connection. Check readiness with:

```text
php artisan processes:health --json
```

## Scheduler operation

Run exactly one scheduler strategy per environment, using the deployment supervisor or the framework scheduler. The application schedules process heartbeats and optional cleanup/expiration tasks. Verify scheduler freshness with `php artisan processes:health --json`; stale heartbeats are degraded or failed operational states.

## Signed inbound webhook

The current inbound endpoint is `POST /api/v1/inbound/webhook`. It uses provider, timestamp, message-ID, and HMAC signature headers, validates a bounded JSON envelope, and queues processing. It is intentionally outside API-key authentication. The complete contract and response codes are documented in [`docs/API_REFERENCE.md`](docs/API_REFERENCE.md).

## Owner API authentication and scopes

Public API routes use API-key authentication and scope checks. Inbox and email reads use `inboxes:read`; inbox mutations and read-state changes use `inboxes:write`; mail-server reads and mutations use `mail_servers:read` and `mail_servers:write`. Ownership checks return a safe not-found response for records outside the caller's visibility. See [`docs/API_REFERENCE.md`](docs/API_REFERENCE.md) for the authoritative endpoint contract.

## Filament administration

Filament is for authorized administrative and operational users. Configure the approved platform/admin access path before exposing it. Normal API users do not gain Filament access merely by possessing an API key.

## ClamAV warning

ClamAV is disabled by default. An unavailable scanner must never be treated as clean; attachments remain pending or enter the bounded retry and terminal-failure lifecycle. Local and CI integration instructions are in [`docs/CLAMAV_INTEGRATION_TESTING.md`](docs/CLAMAV_INTEGRATION_TESTING.md).

## Test commands

```text
php artisan test
php artisan test --filter=InboundWebhook
php artisan test --filter=ClamAv
php artisan test --filter=AttachmentScannerHealth
php artisan test --filter=ScanInboundAttachment
```

The full quality workflow is available through `composer quality` when its required tools are installed. ClamAV integration tests are guarded and skip without `RUN_CLAMAV_TESTS=1`; CI runs them against its service and fails on an unexpected skip.

## Relational concurrency tests

Run the focused scenarios with:

```text
php artisan test --filter=RelationalInboxConcurrencyTest
php artisan test --filter=RelationalApiKeyConcurrencyTest
```

These tests may be environment-gated when the required relational database and concurrency harness are unavailable.

## Health and operations commands

```text
php artisan inbound:health
php artisan attachments:scanner-health --json
php artisan processes:health --json
```

Health commands fail closed. `healthy` is not reported when the relevant dependency is unavailable; degraded/failed output requires operator investigation.

## Documentation index

- [`docs/API_REFERENCE.md`](docs/API_REFERENCE.md) — owner API and signed webhook contract
- [`docs/CLAMAV_INTEGRATION_TESTING.md`](docs/CLAMAV_INTEGRATION_TESTING.md) — local/CI scanner integration
- [`docs/PROCESS_OPERATIONS.md`](docs/PROCESS_OPERATIONS.md) — worker and scheduler operations
- [`docs/PROCESS_RUNTIME_VERIFICATION.md`](docs/PROCESS_RUNTIME_VERIFICATION.md) — readiness verification
- [`docs/INBOUND_RETENTION_POLICY.md`](docs/INBOUND_RETENTION_POLICY.md) — retention and legal holds
- [`docs/RELATIONAL_TEST_MATRIX.md`](docs/RELATIONAL_TEST_MATRIX.md) — concurrency coverage
- [`docs/PRODUCTION_RUNBOOK.md`](docs/PRODUCTION_RUNBOOK.md) — deployment and incident operations

## Security defaults

The application uses scoped API keys, owner isolation, bounded inputs, safe error responses, private attachment storage, signed webhook requests, redacted operational output, and fail-closed health behavior. Keep debug mode disabled in production and never place credentials or message content in logs, metrics, documentation, or test artifacts.

## Known production prerequisites

Before production traffic, provide reviewed database and queue capacity, a supervised worker fleet, exactly one scheduler strategy, fresh heartbeat monitoring, HTTPS and reverse-proxy limits, private attachment storage, backups and restore drills, retention/legal-hold procedures, signed provider secrets, and an approved scanner decision. Anonymous or scheduled capabilities must be explicitly enabled and tested; otherwise they should fail closed.
