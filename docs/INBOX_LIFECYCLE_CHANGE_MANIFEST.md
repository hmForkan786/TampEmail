# Inbox Lifecycle Change Manifest

> **Historical document**
>
> This file records an earlier implementation phase and is not the current
> product, API, deployment, or operational contract.
>
> Use [`README.md`](../README.md) and the current documentation index for
> authoritative guidance.

This manifest isolates the remaining Inbox lifecycle-core work from the dirty working tree. It covers creation, deactivation, renewal, expiration, lifetime policy, mutation context, owned Inbox visibility, lifecycle configuration, lifecycle documentation and lifecycle-specific tests.

Only files explicitly listed in the Included Inbox Lifecycle Files section may be staged for this change set.

Do not use:

- `git add .`
- `git add -A`
- wildcard staging
- whole-file staging of `bootstrap/app.php`

`bootstrap/app.php` must be staged with patch-level selection. Unrelated dirty changes must remain untouched.

## Included Inbox Lifecycle Files

All paths below currently exist. `M` means modified and `??` means untracked in the current checkout.

| Exact path | Status | Ownership | Reason | Dependency | Staging method |
|---|---|---|---|---|---|
| `app/Actions/Inbox/CreateInboxAction.php` | `M` | lifecycle-core | Concurrency-safe Inbox creation and quota/capacity flow. | Existing domain, quota and MailServer services. | exact path |
| `app/Actions/Inbox/DeleteInboxAction.php` | `M` | lifecycle-core | Owner deactivation and lifecycle audit context. | Inbox mutation context and audit conventions. | exact path |
| `app/Actions/Inbox/RenewInboxAction.php` | `??` | lifecycle-core | Owner-scoped expiration renewal action. | Lifetime policy and mutation context. | exact path |
| `app/DTOs/Inbox/InboxMutationContext.php` | `??` | lifecycle-core | Safe actor/source context for Inbox mutations. | Existing audit writer. | exact path |
| `app/Exceptions/InboxRenewalException.php` | `??` | lifecycle-core | Stable renewal-domain failure mapping. | Renewal action/controller boundary. | exact path |
| `app/Services/Inbox/InboxService.php` | `M` | lifecycle-core | Inbox lifecycle service behavior and visibility integration. | Inbox model and existing provisioning conventions. | exact path |
| `app/Services/Inbox/ExpireInboxesService.php` | `??` | lifecycle-core | Bounded, idempotent expiration processing. | Lifetime policy and audit writer. | exact path |
| `app/Services/Inbox/InboxLifetimePolicy.php` | `??` | lifecycle-core | Canonical lifetime and renewal boundary resolver. | `config/inbox_lifetime.php`. | exact path |
| `app/Services/Inbox/OwnedInboxVisibilityService.php` | `??` | lifecycle-core | Owner-scoped active/non-deleted Inbox visibility. | Inbox lifecycle state. | exact path |
| `config/inbox_lifetime.php` | `??` | lifecycle-core | Canonical lifetime, renewal and expiration settings. | Lifetime policy, creation and expiration consumers. | exact path |
| `config/inboxes.php` | `??` | lifecycle-core | Inbox lifecycle compatibility/configuration source. | Existing Inbox consumers. | exact path |
| `docs/INBOX_LIFETIME_POLICY.md` | `??` | lifecycle-documentation | Documents lifetime, renewal and expiration policy. | Lifetime policy implementation. | exact path |
| `bootstrap/app.php` — `inboxes:expire` scheduling hunk only | `M` | shared/mixed | Registers the optional daily expiration scheduler. | `ExpireInboxesService` and `ExpireInboxes` command. | patch-level only |
| `tests/Feature/AnonymousInboxProvisioningTest.php` | `M` | lifecycle-test | Covers anonymous Inbox provisioning lifecycle behavior. | Create action and Inbox policy. | exact path |
| `tests/Feature/InboxQuotaEnforcementTest.php` | `M` | lifecycle-test | Covers creation quota enforcement and lifecycle invariants. | Create action and quota services. | exact path |
| `tests/Feature/ExpireInboxesTest.php` | `??` | lifecycle-test | Covers dry-run, confirmation, batching, idempotency and audit rollback. | ExpireInboxesService. | exact path |
| `tests/Feature/InboxLifetimeConfigTest.php` | `??` | lifecycle-test | Covers canonical lifetime configuration and legacy fallback. | InboxLifetimePolicy/config. | exact path |
| `tests/Feature/InboxMutationContextTest.php` | `??` | lifecycle-test | Covers safe mutation context and audit propagation. | InboxMutationContext and actions. | exact path |
| `tests/Feature/InboxLifecycleAuditTest.php` | `??` | lifecycle-test | Covers create/deactivate lifecycle audit behavior. | Create/Delete actions and audit writer. | exact path |
| `tests/Unit/InboxCreateLockOrderEvidenceTest.php` | `??` | lifecycle-test | Documents creation lock-order evidence. | CreateInboxAction. | exact path |

Included lifecycle paths: **21 exact entries**, counting the `bootstrap/app.php` scheduling hunk as one path-level entry.

## Migration Decision

`database/migrations/2026_07_23_000000_add_read_state_to_emails_table.php` is **excluded**. It adds Email read-state storage and is not directly required by Inbox creation, deactivation, renewal, expiration or lifetime policy. It belongs to the Email/API change set.

## Excluded Files and Categories

The following are explicitly outside this lifecycle-core change set:

- API controllers, requests, resources and routes, including `app/Http/Controllers/Api/V1/*`, `app/Http/Requests/*`, `app/Http/Resources/*` and `routes/api.php`.
- `tests/Feature/InboxLifecycleApiContractTest.php` and `tests/Feature/InboxRenewalApiTest.php`; these are API-owned contract suites.
- Email read-state model/resource/controller/test files and the read-state migration.
- Attachment download and scanning services, jobs, health commands, config and tests.
- Inbound processing, webhook, metrics, failure/DLQ and replay files and tests.
- ClamAV adapter, scanner health, scanner integration fixtures and Docker test configuration.
- Relational concurrency repository changes, harnesses, workers, tests and documentation.
- `.env.example`, CI workflow changes, `phpunit.xml` and unrelated configuration changes.
- Process-operations commit files, including heartbeat writer/readiness, health CLI/UI, Supervisor templates and process verification artifacts.
- `storage/test-results/` and all generated/runtime artifacts.
- Local `.env.process-verification`, logs, cache files, PID files, temporary Redis data, Docker volumes and IDE metadata.
- Any path not explicitly listed in the Included Inbox Lifecycle Files section.

## Dependency Review

The included actions and services do not require API controllers/resources/requests, inbound jobs, attachment scanning, Email read-state storage, process operations, relational concurrency changes, `.env.example` or CI changes for their core lifecycle behavior. API endpoints may call these actions in a separate commit, but that consumer relationship does not make API files part of this manifest.

The expiration scheduler hunk depends on the expiration command/service and the existing Laravel scheduler, but process heartbeat/readiness does not depend on Inbox expiration. It is an independent scheduled Inbox lifecycle task.

## `bootstrap/app.php` Hunk Review

Remaining worktree changes contain four logical changes:

1. ClamAV import — excluded.
2. ClamAV binding — excluded.
3. Scheduler heartbeat registration — already committed in the process-operations commit; do not stage again.
4. `inboxes:expire` scheduling — lifecycle-core, independently stageable with an exact patch.

Whole-file staging is unsafe. Use patch mode or a temporary patch/worktree strategy and retain only the `inboxes:expire` registration. Inbound cleanup formatting and logic must remain unstaged.

## Safety Rule

Only files explicitly listed in the Included Inbox Lifecycle Files section may be staged for this change set.

Before staging, verify:

```bash
git status --short
git diff --name-status
git diff --check
```

No staging, cleanup, deletion, reset or commit was performed while creating this manifest.
