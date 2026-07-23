# Process Operations Change Manifest

This manifest is the allowlist for the Prompt 415–440 process-operations change set. It was prepared from the current `git status --short`, `git diff --name-status`, and `git ls-files --others --exclude-standard` state.

Only files explicitly listed in the Included Process Operations Files section may be staged for this change set.

Do not use directory-wide staging, `git add .`, `git add -A`, wildcard staging, or broad pathspecs. Staging must use exact paths from this manifest. Generated files and unrelated dirty worktree changes must remain untouched. Any new unexpected path requires a fresh audit before staging.

## Included Process Operations Files

All paths below currently exist. `??` means untracked; `M` means modified in the current worktree.

### 1. Heartbeat and readiness

| Path | Status | Reason | Dependency |
|---|---|---|---|
| `app/Services/Ops/ProcessHeartbeatWriter.php` | `??` | Worker and scheduler heartbeat persistence, atomic worker registry update, TTL and safe metadata. | None |
| `app/Services/Ops/ProcessReadinessService.php` | `??` | Reads heartbeat records and aggregates queue, worker, scheduler and lock readiness. | Heartbeat writer |
| `app/Providers/AppServiceProvider.php` | `M` | Queue lifecycle event hooks that invoke the heartbeat writer. This file contains unrelated provider changes and must be reviewed by hunk before staging. | Heartbeat writer |
| `bootstrap/app.php` | `M` | Scheduler heartbeat registration and overlap protection. This file contains unrelated bootstrap changes and must be reviewed by hunk before staging. | Heartbeat writer |
| `config/processes.php` | `??` | Process heartbeat, worker and readiness thresholds. | Writer and readiness service |

Suggested commit: `feat(ops): add safe process heartbeats and readiness`

### 2. Health CLI and Filament UI

| Path | Status | Reason | Dependency |
|---|---|---|---|
| `app/Console/Commands/ProcessHealth.php` | `??` | Safe human/JSON process-health command and exception boundary. | Readiness service |
| `app/Filament/Admin/Pages/ProcessHealth.php` | `??` | Read-only, authorized Process Health page. | Readiness service |
| `resources/views/filament/admin/pages/process-health.blade.php` | `??` | Escaped view for health status and safe heartbeat details. | Filament page |

Suggested commit: `feat(ops): add process health reporting`

### 3. Deployment artifacts and operations runbook

| Path | Status | Reason | Dependency |
|---|---|---|---|
| `deploy/supervisor/temail-worker.conf.example` | `??` | Environment-neutral queue worker Supervisor template. | Queue configuration |
| `deploy/supervisor/temail-scheduler.conf.example` | `??` | Environment-neutral scheduler Supervisor template. | Scheduler configuration |
| `docs/PROCESS_OPERATIONS.md` | `??` | Worker, scheduler, restart, rollback, health and failed-job runbook. | Health CLI and deployment templates |
| `docs/PRODUCTION_READINESS.md` | `??` | Existing production-readiness evidence and operational limitations. | Health and runtime documentation |

Suggested commit: `docs(ops): add process deployment runbook`

### 4. Runtime verification environment

| Path | Status | Reason | Dependency |
|---|---|---|---|
| `docker-compose.process-verification.yml` | `??` | Isolated local Redis verification service definition. | Runtime verification documentation |
| `docs/PROCESS_RUNTIME_VERIFICATION.md` | `??` | Docker, native Redis, WSL, preflight, two-worker and cleanup workflow. | Preflight command and health CLI |

The local `.env.process-verification.example` exists but is ignored by the repository state and is therefore not in the commit allowlist. It must remain local-only; see the exclusions section.

Suggested commit: `docs(ops): document isolated process verification`

### 5. Verification preflight

| Path | Status | Reason | Dependency |
|---|---|---|---|
| `app/Console/Commands/ProcessVerificationPreflight.php` | `??` | Fail-closed validation for isolated queue, cache, Redis, database, heartbeat and Git state. | Runtime verification contract |

Suggested commit: `feat(ops): add process verification preflight`

### 6. Process operations tests

| Path | Status | Reason | Dependency |
|---|---|---|---|
| `tests/Feature/ProcessHealthTest.php` | `??` | Health aggregation, thresholds, safe output and failure-path coverage. | Health CLI and readiness service |
| `tests/Feature/ProcessHeartbeatWriterTest.php` | `??` | Lifecycle, throttling, TTL, registry and cache-failure coverage. | Heartbeat writer |
| `tests/Feature/FilamentProcessHealthPageTest.php` | `??` | Authorization, read-only behavior and safe UI rendering. | Filament page |
| `tests/Feature/ProcessOperationsDocumentationTest.php` | `??` | Supervisor and operations runbook contract coverage. | Deployment artifacts and runbook |
| `tests/Feature/ProcessRuntimeVerificationDocumentationTest.php` | `??` | Docker/native/WSL runtime verification documentation safety coverage. | Runtime documentation |
| `tests/Feature/ProcessVerificationPreflightTest.php` | `??` | Preflight validation, exit codes, JSON schema and secret-safety coverage. | Preflight command |

Suggested commit: `test(ops): cover process operations contracts`

## Explicitly Excluded Paths

Anything not explicitly allowlisted above is excluded by default, even if it appears process-adjacent.

Known unrelated changed categories include:

- `app/Actions/Inbox/*`
- `app/Http/Controllers/Api/V1/*`
- `app/Services/Inbound/*`
- Inbox, API, email, attachment, retention and audit tests
- `app/Models/Email.php`
- `app/Repositories/Eloquent/EloquentMailServerRepository.php`
- `app/Services/Email/*`
- `app/Services/Inbox/*`
- `database/migrations/*`
- `routes/api.php`
- `app/Http/Resources/EmailResource.php`
- `app/Http/Requests/Email/*`
- `app/Http/Requests/Inbox/*`
- `app/Http/Resources/Inbox*`
- `app/Actions/Inbound/*`
- `app/Jobs/ProcessInboundMessageJob.php`
- `app/Jobs/ScanInboundAttachmentJob.php`
- `config/attachments.php`
- `config/inboxes.php`
- `config/inbox_lifetime.php`
- `config/inbound_metrics.php`
- `.env.example` changes unrelated to process operations
- `.github/workflows/tests.yml` changes unrelated to process operations
- `docker-compose.test.yml`
- `docs/API_REFERENCE.md`
- `docs/RELATIONAL_CONCURRENCY_PROTOCOL.md`
- `docs/RELATIONAL_TEST_MATRIX.md`
- `tests/Support/RelationalConcurrencyHarness.php`
- `tests/Support/relational_worker.php`
- all other changed or untracked paths not listed in the Included section

Mixed files such as `app/Providers/AppServiceProvider.php` and `bootstrap/app.php` may only contribute the exact process-operation hunks after manual review; unrelated hunks remain excluded.

## Generated and Local-Only Exclusions

The following must never be staged for this change set:

- `storage/test-results/`
- `.env.process-verification`
- `.env.process-verification.example` when it is ignored/local-only in the current checkout
- local logs and log archives
- cache files
- PID files
- temporary Redis data
- Docker runtime volumes
- IDE metadata
- local absolute-path artifacts
- any secret-bearing environment file, credential file, private key or connection string

Do not delete these files as part of manifest preparation. Do not use Redis-wide cleanup commands such as `FLUSHALL` or unconditional `FLUSHDB`.

## Staging and Validation Rules

Use only exact paths from the Included section, after reviewing mixed-file hunks. Never stage a directory, wildcard, or the whole working tree. Generated files must not be staged. Unrelated dirty changes must remain untouched.

Before any future staging, rerun:

```bash
git status --short
git diff --name-status
git ls-files --others --exclude-standard
git diff --check
```

This manifest itself is the only new file created by Prompt 441. No staging or commit was performed while creating it.
