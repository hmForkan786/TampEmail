# Relational Concurrency Worker Protocol

Status: test-only worker protocol implemented by `tests/Support/RelationalConcurrencyHarness.php` and `tests/Support/relational_worker.php`. CI MySQL 8.4 and PostgreSQL 16 jobs execute the inbox and API-key scenarios with `RUN_RELATIONAL_TESTS=1`. Local runs remain unverified when those engines are unavailable.

## Execution guard

Workers may run only when all conditions hold:

```text
RUN_RELATIONAL_TESTS=1
DB_CONNECTION=mysql|pgsql
```

SQLite, an in-memory database, missing required PDO extensions, unavailable database, or unavailable process APIs must produce an explicit environment skip locally. In CI relational jobs, an unexpected skip or missing harness must fail the job. No SQLite or same-process pre-lock assertion is concurrency evidence.

The parent must verify the target database name is the disposable test database configured by the relational matrix. Production database names and hosts are rejected before launch.

## Worker launch and isolation

The parent test creates a fixture, creates a temporary run directory, and starts exactly two independent PHP processes through `proc_open()` (or an equivalent process API). The test-only entrypoint is:

```text
php tests/Support/relational_worker.php --run=<run-id> --worker=a --scenario=api-key-quota
```

Worker IDs are opaque labels such as `a` and `b`. Scenario names are restricted to:

```text
api-key-quota
inbox-user-quota
mail-server-capacity
anonymous-capacity
```

Workers inherit only the validated test database environment and a run directory. They receive fixture IDs and non-secret DTO input through a temporary JSON file or validated environment variables. They never receive API tokens, key hashes, passwords, authorization headers, or database credentials as arguments. Each worker boots Laravel independently and opens its own database connection; no connection, transaction, model instance, or lock is shared with the parent or another worker.

## Barrier protocol

1. Parent creates a random run directory with restrictive permissions and writes a run manifest containing only scenario, worker IDs, and fixture IDs.
2. Each worker validates the manifest, opens its independent database connection, and atomically creates `ready.<worker-id>` using exclusive file creation.
3. Each worker waits until all expected `ready.*` files exist.
4. Parent waits for both ready signals, then atomically creates `start`.
5. Workers begin the real Action call only after observing `start`.
6. Parent enforces a bounded timeout. A worker that does not become ready or finish before the deadline is `timeout`; the parent terminates all remaining workers and fails the scenario.

The barrier uses exclusive file creation and polling with a monotonic deadline. It is suitable for local filesystem and CI runners, but not a distributed filesystem. Cross-platform process-signal and file-lock differences must be handled by the test-only harness. Temporary barrier files, child processes, and run directories are removed in a `finally` cleanup path; orphan cleanup is attempted after timeout.

## Fixture lifecycle

The parent creates all fixtures using factories/actions before launch and records only IDs. Workers call the real production path:

- API key scenarios call `CreateApiKeyAction::issue()` or `execute()`.
- Inbox scenarios call `CreateInboxAction::execute()`.

Workers must not call `lockForUpdate()` themselves before the Action. They must not insert rows directly to simulate success. The parent verifies final counts and relevant exception outcomes after all workers finish. Cleanup is limited to the disposable test database and follows the test framework transaction/database reset lifecycle.

## Scenario matrix

| Scenario | Required fixture | Expected result | Final verification | Expected rejection |
|---|---|---|---|---|
| `api-key-quota` | One active user, `max_api_keys=1`, no existing active key | One success, one rejection | One persisted non-revoked key | `ApiKeyQuotaExceededException` |
| `inbox-user-quota` | One active user, `max_inboxes=1`, entitled domain/pool and available server | One success, one rejection | One persisted inbox for the user | `InboxQuotaExceededException` |
| `mail-server-capacity` | Active server with `max_inboxes=1`, two eligible authenticated creation inputs | One success, one capacity rejection | Server inbox count never exceeds one | `EligibleMailServerUnavailableException` or the documented capacity-domain exception |
| `anonymous-capacity` | Public pool server with `max_inboxes=1`, two anonymous creation inputs | One success, one capacity rejection | Public server inbox count never exceeds one | `EligibleMailServerUnavailableException` or the documented capacity-domain exception |

Expired, deleted, and inactive inboxes must be included in a separate fixture-count verification so they are excluded according to the existing repository/service query. They must not be used as a fake concurrency result. User-quota and server-capacity scenarios must verify both constraints independently.

## Worker result contract

Each worker writes exactly one JSON object to its result file and emits no credential-bearing output:

```json
{
  "worker_id": "a",
  "scenario": "api-key-quota",
  "status": "success",
  "exception": null,
  "created_id": "opaque-test-id",
  "duration_ms": 42
}
```

Allowed `status` values are `success`, `rejected`, `error`, and `timeout`. An expected domain exception is represented as `rejected` with a class/code, never a stack trace containing secrets. `created_id` is an opaque database ID only; tokens and hashes are forbidden.

The parent emits a summary:

```json
{
  "scenario": "api-key-quota",
  "workers": 2,
  "successes": 1,
  "rejections": 1,
  "errors": 0,
  "final_count": 1,
  "assertion": "PASS"
}
```

`assertion` is `UNVERIFIED` only for a locally unavailable environment and must be reported as skipped. In CI relational jobs it must be `PASS` or the job fails. Malformed JSON, duplicate/missing results, worker crash, unexpected exception, timeout, or wrong scenario is a parent failure.

## Failure propagation and security

- Database unavailable or required extension missing: explicit environment skip locally; CI job failure.
- Process API unavailable: explicit environment skip locally; CI job failure.
- Worker crash, non-zero exit, timeout, malformed JSON, or missing result: parent failure.
- Expected quota/capacity exception: normal rejected worker result.
- Credentials, tokens, hashes, request bodies, and database passwords are never placed in arguments, manifests, result JSON, stdout, stderr, or artifacts.
- Result artifacts contain counts, exception class/code, opaque IDs, and timing only.
- The parent rejects any configured database outside the disposable relational test allowlist.

## CI policy and JUnit mapping

The SQLite job runs the full suite and retains the intentional relational skips. MySQL and PostgreSQL jobs set `RUN_RELATIONAL_TESTS=1`, wait for service health, migrate fresh, and run `RelationalInboxConcurrencyTest` plus `RelationalApiKeyConcurrencyTest` with `--fail-on-skipped` (PHPUnit's disallow-skipped equivalent). Sanitized worker summaries under `storage/test-results/relational/` and JUnit XML are uploaded even on failure. The harness must be invoked in those jobs; an unexpected skip is a failure, not a pass. Parent assertions are regular PHPUnit/Pest assertions so worker failures appear in JUnit output.

Local service startup and database variables are defined in [RELATIONAL_TEST_MATRIX.md](RELATIONAL_TEST_MATRIX.md). The deprecated `RUN_RELATIONAL_CONCURRENCY_TESTS` variable remains a compatibility fallback only.

Renewal versus scheduled expiration is intentionally out of scope for the current scenario allowlist; see the follow-up dependency section in the matrix document.

## Prompt 363 implementation contract

Implement only the test-side harness and worker entrypoint described here:

1. Add `tests/Support/RelationalConcurrencyHarness.php` and the worker entrypoint.
2. Validate database guard, disposable target, process API, timeout, and barrier before launch.
3. Pass only fixture IDs and safe DTO input to workers.
4. Call the real Action paths and classify only documented domain exceptions as `rejected`.
5. Assert one-success/one-rejection and final counts for all four scenarios.
6. Fail CI on unexpected skip, worker failure, timeout, malformed JSON, or assertion mismatch.
7. Add harness failure, timeout, malformed-result, cleanup, and security-redaction tests that do not claim database concurrency on SQLite.
