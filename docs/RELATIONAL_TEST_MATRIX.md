# Relational Verification Matrix

The default test suite uses SQLite in-memory for fast, isolated coverage. SQLite is not accepted as proof of row-locking or independent-transaction concurrency.

The worker lifecycle and result contract are defined in [RELATIONAL_CONCURRENCY_PROTOCOL.md](RELATIONAL_CONCURRENCY_PROTOCOL.md). Inbox quota, MailServer capacity, anonymous public-pool capacity, and API-key quota scenarios are wired to `RelationalConcurrencyHarness`.

## Disposable local database environment

The repository includes `docker-compose.test.yml` with isolated, non-persistent MySQL 8.4 and PostgreSQL 16 services. It uses host ports `13306` and `15432`, separate from the application's normal database port. The services share only the disposable `relational_test` network and have no persistent volumes.

Copy `.env.testing.example` to `.env.testing`, select one database, and generate a test key:

```powershell
Copy-Item .env.testing.example .env.testing
docker compose -f docker-compose.test.yml up -d mysql
php artisan key:generate --env=testing
php artisan migrate:fresh --env=testing --force
php artisan test --env=testing --filter=RelationalInboxConcurrencyTest --fail-on-skipped
docker compose -f docker-compose.test.yml down -v
```

PostgreSQL equivalent:

```powershell
docker compose -f docker-compose.test.yml up -d postgres
# Set DB_CONNECTION=pgsql and DB_PORT=15432 in .env.testing first.
php artisan key:generate --env=testing
php artisan migrate:fresh --env=testing --force
php artisan test --env=testing --filter=RelationalInboxConcurrencyTest --fail-on-skipped
docker compose -f docker-compose.test.yml down -v
```

Wait for the service health check to report `healthy` before migrating. A failed health check is a database-environment failure, not a test skip. The application must connect using `127.0.0.1` and the mapped host port for local workers; Compose service names (`mysql`, `postgres`) are documented for containerized workers only. Never copy production `.env` values into `.env.testing`.

## Current classification

| Test file | Scenarios | Classification | Current result |
|---|---|---|---|
| `RelationalInboxConcurrencyTest.php` | User quota, MailServer capacity, anonymous public-pool capacity | MySQL/PostgreSQL-required | Skipped on SQLite; required (no skips) in MySQL/PostgreSQL CI |
| `RelationalApiKeyConcurrencyTest.php` | API-key quota boundary | MySQL/PostgreSQL-required | Skipped on SQLite; required (no skips) in MySQL/PostgreSQL CI |
| `InboxCreateLockOrderEvidenceTest.php` | Authenticated/anonymous create lock-order source evidence | Always runnable | Not a concurrency VERIFIED claim |

Intentional SQLite skips: harness-gate cases plus real concurrency scenarios in the two relational feature files. Same-process pre-lock assertions are deliberately not used as concurrency evidence.

## Configuration

Default SQLite run:

```text
TEST_DB_CONNECTION=sqlite
RUN_RELATIONAL_TESTS=0
```

Relational run, using credentials supplied only through the environment:

```text
RUN_RELATIONAL_TESTS=1
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=<database>
DB_USERNAME=<username>
DB_PASSWORD=<password>
php artisan migrate:fresh --force
php artisan test --env=testing --filter=RelationalInboxConcurrencyTest --fail-on-skipped
```

## GitHub Actions jobs

The workflow defines:

- `SQLite / PHP 8.4`: full suite, SQLite migration, lint, diff check, and JUnit artifact. Relational scenarios skip and that is accepted.
- `mysql / PHP 8.4`: MySQL 8.4 service, health wait, fresh migration, `RelationalInboxConcurrencyTest` and `RelationalApiKeyConcurrencyTest` with `--fail-on-skipped`, JUnit + worker summary artifacts.
- `postgres / PHP 8.4`: PostgreSQL 16 service with the same relational gate.

`--fail-on-skipped` is the PHPUnit equivalent of the prompt's `--disallow-skipped` requirement: any remaining skip fails the relational job. Worker summary JSON under `storage/test-results/relational/` is uploaded even when the job fails.

## Required concurrency harness

`tests/Support/RelationalConcurrencyHarness.php` launches independent PHP workers via `proc_open()`, each with its own Laravel boot and database connection. Relational feature tests use `DatabaseTruncation` so parent fixtures are committed and visible to workers (RefreshDatabase transactions would hide fixtures from child processes).

Scenarios:

- Inbox quota: concurrent `CreateInboxAction` at `max_inboxes=1` for one user.
- MailServer capacity: two eligible users, `MailServer.max_inboxes=1`.
- Anonymous capacity: `PUBLIC_MAIL_SERVER_POOL=public`, server capacity 1.
- API-key quota: concurrent `CreateApiKeyAction::issue()` at `max_api_keys=1`.

Manual pre-locking, serialized calls, fake parallelism, and SQLite are not valid substitutes.

## Follow-up dependency: renewal vs scheduled expiration

Not added in this matrix. Blockers:

1. Protocol scenario allowlist covers create-only Action workers; renew/expire are not registered harness scenarios.
2. `ExpireInboxesService::process()` is batch-scoped, not a single-inbox Action entrypoint suitable for the two-worker create contract without expanding the protocol.
3. Eligibility predicates conflict at the boundary (`RenewInboxAction` rejects expired rows; expiration requires `expires_at <= now()`), so a non-flaky simultaneous race fixture is not expressible without sleep-based timing or production lock changes.

Track as an explicit follow-up after a single-inbox expiration Action (or harness scenario) and a documented eligibility window exist.

## Current verification

SQLite: relational scenarios intentionally skipped; lock-order evidence tests pass. MySQL and PostgreSQL: claim **VERIFIED** only after CI relational jobs pass with zero unexpected skips. Local environments without Docker/MySQL/PostgreSQL must not claim VERIFIED.
