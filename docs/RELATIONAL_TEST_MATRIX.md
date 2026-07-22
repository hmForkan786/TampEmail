# Relational Verification Matrix

The default test suite uses SQLite in-memory for fast, isolated coverage. SQLite is not accepted as proof of row-locking or independent-transaction concurrency.

Inbound recipient routing design is documented in [INBOUND_ROUTING_CONTRACT.md](INBOUND_ROUTING_CONTRACT.md). It is a contract only; no inbound resolver or ingestion path is currently verified.

The worker lifecycle and result contract are defined in [RELATIONAL_CONCURRENCY_PROTOCOL.md](RELATIONAL_CONCURRENCY_PROTOCOL.md). The API-key quota and authenticated inbox-user quota scenarios are now wired to the harness; MailServer-capacity and anonymous-capacity scenarios remain separate implementation gaps.

## Disposable local database environment

The repository includes `docker-compose.test.yml` with isolated, non-persistent MySQL 8.4 and PostgreSQL 16 services. It uses host ports `13306` and `15432`, separate from the application's normal database port. The services share only the disposable `relational_test` network and have no persistent volumes.

Copy `.env.testing.example` to `.env.testing`, select one database, and generate a test key:

```powershell
Copy-Item .env.testing.example .env.testing
docker compose -f docker-compose.test.yml up -d mysql
php artisan key:generate --env=testing
php artisan migrate:fresh --env=testing --force
php artisan test --env=testing --filter=Relational
docker compose -f docker-compose.test.yml down -v
```

PostgreSQL equivalent:

```powershell
docker compose -f docker-compose.test.yml up -d postgres
# Set DB_CONNECTION=pgsql and DB_PORT=15432 in .env.testing first.
php artisan key:generate --env=testing
php artisan migrate:fresh --env=testing --force
php artisan test --env=testing --filter=Relational
docker compose -f docker-compose.test.yml down -v
```

Wait for the service health check to report `healthy` before migrating. A failed health check is a database-environment failure, not a test skip. The application must connect using `127.0.0.1` and the mapped host port for local workers; Compose service names (`mysql`, `postgres`) are documented for containerized workers only. Never copy production `.env` values into `.env.testing`.

## Current classification

| Test file | Scenarios | Classification | Current result |
|---|---|---|---|
| `RelationalInboxConcurrencyTest.php` | Inbox quota and MailServer capacity across independent transactions | MySQL/PostgreSQL-required | Skipped unless explicitly enabled with a relational driver and external runner |
| `RelationalApiKeyConcurrencyTest.php` | API-key quota boundary and rollback/isolation | MySQL/PostgreSQL-required | Skipped unless explicitly enabled with a relational driver and external runner |

The six skipped cases are intentional: two harness-gate cases and four real concurrency scenarios. No skipped case is environment-independent, obsolete, or a duplicate. Same-process pre-lock assertions are deliberately not used because they can produce false-positive concurrency results.

## Configuration

Default SQLite run:

```text
TEST_DB_CONNECTION=sqlite
RUN_RELATIONAL_TESTS=0
```

The repository's PHPUnit configuration defaults to SQLite in-memory but does not override an explicitly supplied CI/local database environment. There is no `.env.testing`; the GitHub Actions workflow is `.github/workflows/tests.yml`.

Relational run, using credentials supplied only through the environment:

```text
TEST_DB_CONNECTION=mysql
RUN_RELATIONAL_TESTS=1
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=<database>
DB_USERNAME=<username>
DB_PASSWORD=<password>
php artisan migrate:fresh --force
php artisan test --group=relational-db
```

## GitHub Actions jobs

The workflow defines:

- `SQLite / PHP 8.4`: full suite, SQLite migration, lint, diff check, and JUnit artifact.
- `mysql / PHP 8.4`: MySQL 8.4 service, fresh migration, full relational suite, and JUnit artifact.
- `postgres / PHP 8.4`: PostgreSQL 16 service, fresh migration, full relational suite, and JUnit artifact.

Relational job failures fail the job; they are not converted to skips. The relational CI command uses `--disallow-skipped`, so any remaining harness gate or unexpected skip fails the job. The workflow does not invent serialized or pre-lock assertions to hide a missing fixture or worker harness.

Local equivalents are the commands above with the corresponding database service running. The deprecated `RUN_RELATIONAL_CONCURRENCY_TESTS` variable remains accepted only as a fallback by the test gates; use `RUN_RELATIONAL_TESTS` for new runs.

PostgreSQL equivalent:

```text
TEST_DB_CONNECTION=pgsql
RUN_RELATIONAL_TESTS=1
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=<database>
DB_USERNAME=<username>
DB_PASSWORD=<password>
php artisan migrate:fresh --force
php artisan test --group=relational-db
```

`migrate:fresh --force` is for an isolated disposable test database only. Never point these commands at production. Credentials must not be committed.

## Required concurrency harness

The current repository has no independent-process runner. A valid implementation must launch at least two workers/processes, each with an independent database connection, and exercise the real Action/Service path:

- Inbox quota: concurrent inbox creation at the same-user boundary, rollback, and two-user isolation.
- MailServer capacity: concurrent reservation at the same-server boundary, anonymous pool handling, and inactive/deleted/expired inbox exclusion.
- API-key quota: concurrent `issue()` calls at `max_api_keys=1`, one success and one quota failure, rollback, revoked exclusion, and expired-key counting.

Manual pre-locking, serialized calls, fake parallelism, and SQLite are not valid substitutes.

## Matrix beyond concurrency

The relational job must also run migration rollback/re-run, exact JSON permission matching, audit-hold active predicates, and bounded retention deletion. These paths must call the real migration, middleware, Action, Service, and cleanup command implementations.

## Current verification

SQLite full suite: **234 passed, 6 intentionally skipped, 1,117 assertions**. The skipped relational tests remain clearly reported rather than being treated as passed. MySQL and PostgreSQL were not available in this environment, so no relational result is claimed.
