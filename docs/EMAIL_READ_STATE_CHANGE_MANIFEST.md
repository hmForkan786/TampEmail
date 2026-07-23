# Email Read-State API Change Manifest

Prompt: **459**
Scope: Isolate owner-scoped Email read/unread mutation endpoints, `EmailResource` read-state response fields, read-state routes, and focused tests from Attachment download, Inbox restaging, inbound, ClamAV, relational concurrency, CI, and generated artifacts.

---

## Classification Legend

| Class | Meaning |
| --- | --- |
| Email read-state controller | Mark read / unread HTTP controller |
| Email read-state request | Dedicated FormRequest for mutation (none present) |
| Email read-state resource | `EmailResource` exposing `is_read` / `read_at` |
| already-committed dependency | Schema/model/visibility already in `7bb5130` |
| shared route | `routes/api.php` mixed remaining hunks |
| mixed contract test | Lifecycle matrix covering Inbox + read-state + attachment |
| mixed documentation | `docs/API_REFERENCE.md` mixes auth, email, attachment, read-state, inbound |
| Attachment API | Attachment download controller/routes/tests |
| Inbox API | Owner Inbox CRUD/listing already committed |
| unrelated | Inbound / ClamAV / relational / CI / env / bootstrap / artifacts |

---

## Required Verification

| Check | Result |
| --- | --- |
| Migration already committed in `7bb5130` | **Yes** — `database/migrations/2026_07_23_000000_add_read_state_to_emails_table.php` |
| `Email.php` required model support already committed | **Yes** — fillable/casts/scopes for `is_read` / `read_at` in `7bb5130` |
| `OwnedEmailVisibilityService` dependency already committed | **Yes** — `resolveOwnedInbox` / `findForInbox` / `is_read` filters in `7bb5130` |
| Inbox API restaging required | **No** |
| Attachment download files required | **No** |
| Inbound / ClamAV / relational / CI required | **No** |

Working-tree confirmation: `git diff -- app/Models/Email.php` and `git diff -- app/Services/Email/OwnedEmailVisibilityService.php` are empty.

---

## Candidate Classification

| Path | Git status | Classification | Decision |
| --- | --- | --- | --- |
| `app/Http/Controllers/Api/V1/EmailReadStateController.php` | `??` untracked | Email read-state controller | **Include** (exact path) |
| `app/Http/Requests/Email/MarkEmailReadRequest.php` | absent | Email read-state request | N/A — controller uses `Illuminate\Http\Request` |
| `app/Http/Requests/Email/MarkEmailUnreadRequest.php` | absent | Email read-state request | N/A — controller uses `Illuminate\Http\Request` |
| `app/Http/Resources/EmailResource.php` | `M` modified | Email read-state resource | **Include** (exact path) |
| `app/Models/Email.php` | clean vs HEAD | already-committed dependency | **Exclude** (already in `7bb5130`) |
| `app/Services/Email/OwnedEmailVisibilityService.php` | clean vs HEAD | already-committed dependency | **Exclude** (already in `7bb5130`) |
| `routes/api.php` | `M` modified | shared route | **Include** (patch-level only) |
| `tests/Feature/EmailReadStateApiTest.php` | `??` untracked | Email read-state controller (focused test) | **Include** (exact path) |
| `tests/Feature/InboxLifecycleApiContractTest.php` | `??` untracked | mixed contract test | **Deferred** |
| `docs/API_REFERENCE.md` | `M` modified | mixed documentation | **Deferred** |

---

## `routes/api.php` Review

Base index after Inbox commit `7bb5130` already contains Inbox CRUD/list/email listing. Remaining dirty hunks vs index:

| Dirty hunk | Classification | Staging action |
| --- | --- | --- |
| `use …\AttachmentDownloadController` | Attachment download | **Exclude** |
| `…/attachments/{attachment}` download route | Attachment download | **Exclude** |
| `PATCH …/emails/{email}/read` via `EmailReadStateController::read` (FQCN) | Email read-state | **Include** (patch) |
| `PATCH …/emails/{email}/unread` via `EmailReadStateController::unread` (FQCN) | Email read-state | **Include** (patch) |
| Surrounding `inboxes:write` middleware group wrapping read/unread | Email read-state | **Include** (patch) |
| Inbound webhook route | inbound (unchanged vs committed base; not part of remaining dirty Inbox-delta beyond attachment/read-state) | **Exclude** from this stage |
| Unrelated formatting | unrelated | **Exclude** |

Notes:

* Working tree uses **FQCN** for `EmailReadStateController`; there is no separate dirty `use EmailReadStateController` import. Patch-stage the read/unread route group as written (FQCN is acceptable).
* Independently patch-stageable: **Yes**
* Whole-file staging of `routes/api.php`: **prohibited**

Exact patch-stageable content:

```text
EmailReadStateController mark-read route
EmailReadStateController mark-unread route
wrapping inboxes:write middleware group for those two routes
```

---

## `EmailResource.php` Decision

**`EmailResource.php` included: Yes.**

| Fact | Detail |
| --- | --- |
| Dirty diff | Only adds `'is_read' => $this->is_read` and `'read_at' => $this->read_at` |
| Mixed attachment/inbound fields in same diff | **No** |
| Whole-file staging safe | **Yes** — atomic two-line resource addition |
| Why include now | Mutation responses return `EmailResource`; `EmailReadStateApiTest` asserts `data.is_read` / `data.read_at` |

Staging method: **exact path**.

---

## Contract Test Decision

`tests/Feature/InboxLifecycleApiContractTest.php` — **deferred**.

| Aspect | Detail |
| --- | --- |
| Exact read-state assertions | Endpoint matrix includes `PATCH …/read` and `PATCH …/unread`; 401 loop covers them; log count expects **10** |
| Exact attachment assertions | Endpoint matrix includes `GET …/attachments/{attachment}` |
| Also includes | Full Inbox CRUD + email list/show (already committed routes) |
| Patch-level staging safe? | **No** — single untracked file; cannot isolate endpoints without rewriting the array and `toBe(10)` assertion |
| Deferral safer? | **Yes** — wait until attachment route also lands, or trim in a dedicated contract prompt |

**Contract test included: No.**

---

## Documentation Decision

`docs/API_REFERENCE.md` — **deferred**.

| Aspect | Detail |
| --- | --- |
| Dirty size | +131 / −2 lines |
| Mixed content | Auth wording changes, email list/show, **read-state**, **attachment download**, inbound webhook, lifecycle/ops, ClamAV limitation |
| Read-state isolatable? | Conceptually a small “### Read state” subsection exists, but it sits inside a large mixed addition |
| Whole-file staging unsafe? | **Yes** — would commit attachment + inbound + ops docs with this scope |
| Patch-level practical? | Unsafe without content surgery; out of this prompt |
| Decision | Remain deferred (P3 documentation gap) |

**Documentation included: No.**

---

## Included Email Read-State Files

Only the paths below may be staged for this change set.

| Path | Git status | Ownership | Purpose | Dependency | Staging method |
| --- | --- | --- | --- | --- | --- |
| `app/Http/Controllers/Api/V1/EmailReadStateController.php` | `??` untracked | Email read-state controller | Owner-scoped mark read/unread; persist `is_read` / `read_at` idempotently | Committed `OwnedEmailVisibilityService`, committed `Email` model/schema, `EmailResource` | **exact path** |
| `app/Http/Resources/EmailResource.php` | `M` modified | Email read-state resource | Expose `is_read` / `read_at` in email JSON | Committed model columns | **exact path** |
| `routes/api.php` | `M` modified | shared route | Register `PATCH …/read` and `PATCH …/unread` under `inboxes:write` | `EmailReadStateController` | **patch-level** |
| `tests/Feature/EmailReadStateApiTest.php` | `??` untracked | Email read-state controller (test) | Idempotent read/unread, ownership/scope, anonymous/expired/inactive/deleted denial | Controller + routes + resource + committed schema | **exact path** |
| `docs/EMAIL_READ_STATE_CHANGE_MANIFEST.md` | created by this prompt | Documentation (manifest only) | Staging/ownership record for this scope | — | **exact path** |

### Included counts

* Included Email read-state paths: **5**
* Read-state tests included: **1** (`EmailReadStateApiTest`)
* Shared/mixed files included: **2** (`routes/api.php`, `EmailResource.php`)

---

## Excluded Files

Explicitly exclude from this change set:

| Path / area | Reason |
| --- | --- |
| Inbox controllers / requests / resources (`InboxController`, Inbox requests, `InboxResource`, `InboxCollection`, …) | Inbox API — already committed in `7bb5130`; no restaging |
| `app/Models/Email.php` | already-committed dependency |
| `app/Services/Email/OwnedEmailVisibilityService.php` | already-committed dependency |
| `database/migrations/2026_07_23_000000_add_read_state_to_emails_table.php` | already-committed dependency |
| `app/Http/Controllers/Api/V1/AttachmentDownloadController.php` | Attachment API |
| `tests/Feature/AttachmentDownloadApiTest.php` | attachment tests |
| Attachment route/import in `routes/api.php` | Attachment API |
| `tests/Feature/InboxLifecycleApiContractTest.php` | mixed contract test — deferred |
| `docs/API_REFERENCE.md` | mixed documentation — deferred |
| Inbound processing/metrics (`InboundWebhookController`, ingest/replay, jobs, Filament failures, inbound metrics, …) | inbound |
| ClamAV / attachment scanner services, health commands, ClamAV tests, `config/attachments.php`, `docs/CLAMAV_INTEGRATION_TESTING.md` | ClamAV |
| Relational concurrency harness/tests/docs | relational concurrency |
| `.github/workflows/tests.yml`, `phpunit.xml`, `docker-compose.test.yml` | CI |
| `.env.example` | unrelated |
| `bootstrap/app.php` | unrelated |
| `storage/test-results/**` | generated artifacts |
| `app/Console/Commands/ExpireInboxes.php` and other process/lifecycle untracked commands | prior process/lifecycle — not this mutation scope |
| Prior committed process/lifecycle/Inbox API files | already committed; do not restage |

---

## Safety Rule

```text
Only files explicitly listed in the Included Email Read-State Files section may be staged for this change set.
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
| F1 | **P2** | `routes/api.php` remaining dirty hunks mix Email read-state with Attachment download. Patch-level staging required; whole-file prohibited. |
| F2 | **P2** | `InboxLifecycleApiContractTest` mixes Inbox, read-state, and attachment endpoints with hard-coded log count 10. Defer. |
| F3 | **P2** | `docs/API_REFERENCE.md` mixes read-state with attachment, inbound webhook, and ops/ClamAV docs. Defer whole file. |
| F4 | **P3** | Read-state API docs remain a documentation gap until a later docs prompt. |
| — | **P0** | None — schema/model/visibility already committed in `7bb5130`. |
| — | **P1** | None — scope isolatable via exact paths + route patch. |

**P0/P1/P2/P3:** P0=0, P1=0, P2=3, P3=1

---

## Final Decision

| Decision | Value |
| --- | --- |
| Required schema already committed | **Yes** |
| Required model dependency already committed | **Yes** |
| `EmailResource.php` included | **Yes** |
| `routes/api.php` independently patch-stageable | **Yes** |
| Contract test included | **No** |
| Documentation included | **No** |
| Scope internally complete | **Yes** |
| Ready for staging-plan validation | **Yes** |
| Highest-priority next single prompt | **Prompt 460 — Email Read-State API staging-plan validation** (exact-path + `routes/api.php` patch plan; no commit) |

---

## Command Evidence Snapshot

* `git diff --check`: **clean** (exit 0)
* Staging performed: **No**
* Commit: **None**

### Inspected candidates / discoveries

`EmailReadStateController`, absent Mark-read/unread FormRequests, `EmailResource` two-field diff, clean `Email` model + `OwnedEmailVisibilityService` (committed in `7bb5130`), remaining `routes/api.php` hunks, `EmailReadStateApiTest`, mixed `InboxLifecycleApiContractTest`, mixed `docs/API_REFERENCE.md`.
