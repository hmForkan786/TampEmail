# Owner-Scoped Inbox API Change Manifest

Prompt: **454**
Scope: Isolate owner-scoped Inbox API (CRUD / renewal / listing / owned-inbox email listing / unread metadata schema) from Email read-state mutation, attachment download, inbound, ClamAV, relational concurrency, CI, and generated artifacts.

---

## Classification Legend

| Class | Meaning |
| --- | --- |
| Inbox API core | Create / list / show / delete / renew owned inboxes |
| Inbox email listing | List/show emails under an owned inbox (filters, visibility) |
| required shared visibility | Shared service required for owner-scoped filtering |
| required migration | Schema for `emails.is_read` / `emails.read_at` |
| shared resource | Resource used by Inbox and later Email read-state commits |
| shared route | `routes/api.php` mixed hunks |
| mixed test | Test covering Inbox plus out-of-scope endpoints |
| Email read-state mutation | Mark read/unread endpoints and controllers |
| Attachment API | Attachment download |
| Documentation | Docs-only changes |
| unrelated | Inbound / ClamAV / relational / CI / env / bootstrap / artifacts |

---

## Migration Decision

**Migration included: Yes.**

Committed `OwnedInboxVisibilityService` already depends on:

```text
emails.is_read
emails.read_at
```

Evidence:

* `withCount(['emails as unread_count' => fn ($q) => $q->where('is_read', false)])`
* `has_unread` filters via `where('is_read', false)`
* `InboxResource` exposes `unread_count`
* Dirty `OwnedEmailVisibilityService` and `ListOwnedEmailsRequest` filter/sort on `is_read`
* Dirty `InboxEmailVisibilityApiTest` seeds `is_read` / `read_at` for filter coverage

`database/migrations/2026_07_23_000000_add_read_state_to_emails_table.php` is additive (`is_read` default `false`, nullable `read_at`, composite index). It can **safely land** in this Inbox API commit and is a **hard schema dependency** for Inbox listing/unread metadata.

Companion model support in dirty `app/Models/Email.php` (fillable, casts, docblocks, `read`/`unread` scopes) is required so Eloquent mass-assignment used by Inbox email listing tests can set `is_read` / `read_at`. Scopes are query helpers over the same columns, not mutation endpoints.

---

## `EmailResource.php` Decision

**`EmailResource.php` included: No.**

| Fact | Detail |
| --- | --- |
| Dirty diff | Only adds `'is_read'` and `'read_at'` to `toArray()` |
| Listing need | Inbox email listing uses `EmailResource`, but current Inbox tests assert ids / totals / validation — **not** response read-state fields |
| Filter need | Query filtering uses `OwnedEmailVisibilityService` + migration columns, not resource output |
| Whole-file staging | Technically safe (single atomic hunk), but **out of scope** — defer to the Email read-state commit so listing responses do not prematurely advertise mutation contract fields |

Staging method: **deferred**.

---

## `routes/api.php` Hunk Review

Whole-file staging of `routes/api.php` is **prohibited**. Independently patch-stageable: **Yes**.

| Dirty hunk | Classification | Staging action |
| --- | --- | --- |
| `use …\AttachmentDownloadController` | Attachment download | **Exclude** |
| `use …\InboxController` | Inbox API | **Include** (patch) |
| `Route::get('inboxes', …)` | Inbox API | **Include** (patch) |
| `Route::get('inboxes/{inbox}', …)` | Inbox API | **Include** (patch) |
| Existing `inboxes/{inbox}/emails` index/show (unchanged context) | Inbox email listing (pre-existing) | Keep as-is; no exclusive stage needed beyond surrounding Inbox patches |
| `…/attachments/{attachment}` download route | Attachment download | **Exclude** |
| `inboxes:write` group: `store` / `destroy` / `renew` | Inbox API | **Include** (patch) |
| Second `inboxes:write` group: `…/read` and `…/unread` via `EmailReadStateController` | Email read-state mutation | **Exclude** |

Exact patch-level staging must add only InboxController import + Inbox CRUD/show/list/renew routes, and must omit AttachmentDownload + EmailReadState imports/routes.

---

## Test Ownership Decision

| Test | Classification | Decision |
| --- | --- | --- |
| `tests/Feature/InboxRenewalApiTest.php` | Inbox API core | **Include** (exact path). Untracked; covers renew / scope / ownership / validation only. |
| `tests/Feature/InboxEmailVisibilityApiTest.php` | Inbox email listing | **Include** (exact path). Dirty hunk adds `is_read` filter + validation envelope tests only; no mutation/attachment assertions. |
| `tests/Feature/InboxLifecycleApiContractTest.php` | mixed test | **Deferred**. |

### Contract test detail

* Endpoint matrix includes Inbox CRUD/renewal/email listing **plus** `PATCH …/read`, `PATCH …/unread`, and attachment download.
* `ApiRequestLog` expectation is hard-coded to **10** endpoints — cannot drop out-of-scope routes via partial hunk staging without rewriting assertions.
* Partial staging is **not safe** without content edits (out of this prompt).
* Whole-file staging would introduce Email read-state and attachment expectations into the Inbox API commit.
* **Contract test included: No** (defer to a later lifecycle/contract prompt after mutation + attachment routes land, or a dedicated trimmed contract).

---

## Included Inbox API Files

Only the paths below may be staged for this change set.

| Path | Git status | Ownership | Purpose | Dependency | Staging method |
| --- | --- | --- | --- | --- | --- |
| `app/Http/Controllers/Api/V1/InboxController.php` | `??` untracked | Inbox API core | Owner-scoped create / list / show / delete / renew | Requests, `InboxResource`/`InboxCollection`, committed Inbox actions/visibility | **exact path** |
| `app/Http/Controllers/Api/V1/InboxEmailController.php` | `M` modified | Inbox email listing | Wire `ListOwnedEmailsRequest` into owned email index | `ListOwnedEmailsRequest`, `OwnedEmailVisibilityService`, `EmailResource` (committed base) | **exact path** |
| `app/Http/Requests/Inbox/StoreOwnedInboxRequest.php` | `??` untracked | Inbox API core | Validate create payload | `InboxController::store` | **exact path** |
| `app/Http/Requests/Inbox/ListOwnedInboxesRequest.php` | `??` untracked | Inbox API core | Validate list filters (`has_unread`, sort by `unread_count`, …) | `InboxController::index`, `is_read` schema | **exact path** |
| `app/Http/Requests/Inbox/RenewOwnedInboxRequest.php` | `??` untracked | Inbox API core | Validate renewal `expires_at` | `InboxController::renew` | **exact path** |
| `app/Http/Requests/Email/ListOwnedEmailsRequest.php` | `??` untracked | Inbox email listing | Validate email list filters including `is_read` | `InboxEmailController::index`, migration columns | **exact path** |
| `app/Http/Resources/InboxCollection.php` | `??` untracked | Inbox API core | Pagination `meta` for inbox collections | `InboxResource` | **exact path** |
| `app/Http/Resources/InboxResource.php` | `??` untracked | Inbox API core | Inbox JSON including `unread_count` / counts | `OwnedInboxVisibilityService` (committed), migration | **exact path** |
| `app/Services/Email/OwnedEmailVisibilityService.php` | `M` modified | required shared visibility | Owner inbox resolve + filtered email pagination (`is_read`, dates, sort, …) | Migration columns; used by `InboxEmailController` | **exact path** |
| `app/Models/Email.php` | `M` modified | required migration (model companion) | Fillable/casts/scopes for `is_read` / `read_at` | Migration; Inbox email listing tests mass-assign columns | **exact path** |
| `database/migrations/2026_07_23_000000_add_read_state_to_emails_table.php` | `??` untracked | required migration | Add `is_read`, `read_at`, index | Committed unread_count / dirty filters | **exact path** |
| `routes/api.php` | `M` modified | shared route | Register Inbox read/write routes only | `InboxController` | **patch-level** (Inbox hunks only) |
| `tests/Feature/InboxRenewalApiTest.php` | `??` untracked | Inbox API core | Renewal API feature coverage | Inbox renew route + controller | **exact path** |
| `tests/Feature/InboxEmailVisibilityApiTest.php` | `M` modified | Inbox email listing | Existing visibility + new filter/validation coverage | Migration + visibility service + list request | **exact path** |

### Candidate name mapping note

Prompt candidates `CreateInboxRequest` / `DeleteInboxRequest` / `ListInboxesRequest` / `RenewInboxRequest` / `ShowInboxRequest` are **not** present under those names. Actual files: `StoreOwnedInboxRequest`, `ListOwnedInboxesRequest`, `RenewOwnedInboxRequest`. Show/delete use generic `Request` + owner queries inside `InboxController`.

### Included counts

* Included Inbox API paths: **14**
* Inbox API tests included: **2** (`InboxRenewalApiTest`, `InboxEmailVisibilityApiTest`)
* Shared/mixed files included: **3** (`routes/api.php`, `OwnedEmailVisibilityService.php`, `Email.php`)

---

## Excluded Files

Explicitly exclude from this change set:

| Path / area | Reason |
| --- | --- |
| `app/Http/Controllers/Api/V1/EmailReadStateController.php` | Email read-state mutation |
| Email read/unread mutation FormRequests (none separate; mutation lives on controller) | Email read-state mutation |
| `app/Http/Resources/EmailResource.php` | shared resource — read-state response fields deferred |
| `tests/Feature/InboxLifecycleApiContractTest.php` | mixed test — deferred |
| `tests/Feature/EmailReadStateApiTest.php` | Email read-state mutation |
| `app/Http/Controllers/Api/V1/AttachmentDownloadController.php` | Attachment API |
| `tests/Feature/AttachmentDownloadApiTest.php` | attachment tests |
| Inbound routes/controllers/actions/jobs (`InboundWebhookController`, ingest/replay actions, process/scan jobs, …) | inbound |
| ClamAV / attachment scanner services, health commands, integration/unit ClamAV tests, `config/attachments.php`, `docs/CLAMAV_INTEGRATION_TESTING.md` | ClamAV |
| Relational concurrency harness/tests/docs (`Relational*`, `relational_worker.php`, RELATIONAL docs) | relational concurrency |
| `.github/workflows/tests.yml`, `phpunit.xml`, `docker-compose.test.yml` | CI |
| `.env.example` | unrelated env |
| `docs/API_REFERENCE.md` | Documentation |
| `storage/test-results/**` | generated artifacts |
| `bootstrap/app.php` | unrelated / process wiring |
| `app/Console/Commands/ExpireInboxes.php`, `InboundHealth.php`, `AttachmentScannerHealth.php` | process and lifecycle / health (not Inbox HTTP API) |
| Filament inbound failure resources/policy, inbound metrics services/config/tests | inbound / unrelated |
| `app/Repositories/Eloquent/EloquentMailServerRepository.php` | unrelated |

---

## Safety Rule

```text
Only files explicitly listed in the Included Inbox API Files section may be staged for this change set.
```

Also prohibit:

```text
git add .
git add -A
wildcard staging
whole-file staging of routes/api.php
```

---

## Findings

| ID | Severity | Finding |
| --- | --- | --- |
| F1 | **P0** | Already-committed `OwnedInboxVisibilityService` / `InboxResource` unread metadata require `emails.is_read` (and related `read_at`). Omitting the migration would leave Inbox listing schema-incomplete. **Mitigation:** include migration + `Email` model companion. |
| F2 | **P2** | `routes/api.php` mixes Inbox API, attachment download, and Email read-state mutation. Patch-level staging required; whole-file staging prohibited. |
| F3 | **P2** | `InboxLifecycleApiContractTest` mixes Inbox, read-state mutation, and attachment endpoints with a fixed log count of 10. Defer whole file. |
| F4 | **P2** | `EmailResource` dirty hunk only adds read-state fields; safe whole-file but out of scope for this commit — defer. |
| F5 | **P3** | `docs/API_REFERENCE.md` documents Inbox + read-state + attachments together; excluded (documentation gap until a docs prompt). |

**P0/P1/P2/P3:** P0=1, P1=0, P2=3, P3=1

---

## Final Decision

| Decision | Value |
| --- | --- |
| Migration included | **Yes** |
| `EmailResource.php` included | **No** |
| `routes/api.php` independently patch-stageable | **Yes** |
| Contract test included | **No** |
| Inbox API scope internally complete | **Yes** |
| Ready for staging-plan validation | **Yes** |
| Highest-priority next single prompt | **Prompt 455 — Inbox API staging-plan validation** (exact-path + `routes/api.php` patch plan; no commit) |

---

## Command Evidence Snapshot

* `git diff --check`: **clean** (exit 0)
* Staging performed: **No**
* Commit: **None**

### Inspected candidate / discovered paths

Controllers, Inbox/Email list requests (actual owned-* names), Inbox resources, `OwnedEmailVisibilityService`, migration, `routes/api.php`, three Inbox feature tests, plus discovered `app/Models/Email.php` and committed `OwnedInboxVisibilityService` unread dependency.
