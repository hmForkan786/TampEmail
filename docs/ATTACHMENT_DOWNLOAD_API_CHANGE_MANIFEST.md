# Attachment Download API Change Manifest

> **Historical document**
>
> This file records an earlier implementation phase and is not the current
> product, API, deployment, or operational contract.
>
> Use [`README.md`](../README.md) and the current documentation index for
> authoritative guidance.

Prompt: **464**
Scope: Isolate owner-scoped attachment download endpoint, authorization, storage safety, route, and focused tests from Inbox restaging, Email read-state, inbound, ClamAV, relational concurrency, CI, and generated artifacts.

---

## Classification Legend

| Class | Meaning |
| --- | --- |
| Attachment API controller | Invokable download controller |
| Attachment API route | Download route / import in `routes/api.php` |
| Attachment API test | Focused download feature tests |
| already-committed dependency | Ownership/visibility/model/policy already in history |
| mixed contract test | Lifecycle matrix covering Inbox + read-state + attachment |
| mixed documentation | `docs/API_REFERENCE.md` mixes email, read-state, attachment, inbound |
| inbound processing | Webhook / ingest / metrics |
| ClamAV | Scanner backend / health / integration |
| unrelated | Relational / CI / env / bootstrap / artifacts |

---

## Required Verification

| Check | Result |
| --- | --- |
| Inbox ownership dependencies already committed | **Yes** — Inbox routes/controllers in `7bb5130`; ownership via committed `OwnedEmailVisibilityService::resolveOwnedInbox` (`ownedBy` + `visibleToOwner`) |
| Email visibility dependency already committed | **Yes** — `OwnedEmailVisibilityService::findForInbox` committed (Inbox + read-state commits) |
| Attachment-to-email relation | **Yes** — committed `Attachment::email()` `belongsTo(Email::class)`; controller scopes `where('email_id', $ownedEmail->getKey())` |
| Storage disk/path behavior explicit | **Yes** — disk must equal `config('platform.storage.attachments_disk')`; path validated by `safeStoragePath`; stream from `Storage::disk($record->storage_disk)` |
| Missing files fail safely | **Yes** — `exists()` false → `isDownloadable` false → `abort(404)` |
| Cross-owner download denied | **Yes** — foreign owner fails `resolveOwnedInbox` → 404 (covered by focused test) |
| Expired/deleted inbox visibility denied | **Yes** — `visibleToOwner()` in resolve; focused test covers expired inbox |
| Read-state files required | **No** |
| Inbound / ClamAV / relational / CI required | **No** |

Working-tree confirmation: `git diff` empty for `app/Models/Attachment.php`, `app/Models/Email.php`, and `app/Services/Email/OwnedEmailVisibilityService.php`.

Already-committed companion (not restaged): `app/Policies/AttachmentVisibilityPolicy.php` (clean/safe/private-disk gate).

Dirty `config/attachments.php` is ClamAV socket/timeout only; `max_bytes` already committed — **exclude**.

---

## Candidate Classification

| Path | Git status | Classification | Decision |
| --- | --- | --- | --- |
| `app/Http/Controllers/Api/V1/AttachmentDownloadController.php` | `??` untracked | Attachment API controller | **Include** (exact path) |
| `app/Models/Attachment.php` | clean vs HEAD | already-committed dependency | **Exclude** |
| `app/Models/Email.php` | clean vs HEAD | already-committed dependency | **Exclude** |
| `app/Services/Email/OwnedEmailVisibilityService.php` | clean vs HEAD | already-committed dependency | **Exclude** |
| `routes/api.php` | `M` modified | Attachment API route (shared file) | **Include** (patch-level only) |
| `tests/Feature/AttachmentDownloadApiTest.php` | `??` untracked | Attachment API test | **Include** (exact path) |
| `tests/Feature/InboxLifecycleApiContractTest.php` | `??` untracked | mixed contract test | **Deferred** |
| `docs/API_REFERENCE.md` | `M` modified | mixed documentation | **Deferred** |

---

## `routes/api.php` Review

Committed base (`8756ef9`) already contains Inbox + read-state routes. Remaining dirty hunks vs HEAD:

| Dirty hunk | Classification | Staging action |
| --- | --- | --- |
| `use …\AttachmentDownloadController` | Attachment download | **Include** (patch) |
| `GET …/attachments/{attachment}` download route | Attachment download | **Include** (patch) |
| Inbound webhook route | inbound | Unchanged vs HEAD; **do not restage** |
| Already committed Inbox/read-state routes | unrelated to this delta | Present in base; **do not restage** |
| Unrelated formatting | unrelated | **Exclude** |

Independently patch-stageable: **Yes**
Whole-file staging of `routes/api.php`: **prohibited**

Exact patch-stageable content:

```text
AttachmentDownloadController import
attachment download route under inboxes:read group
```

---

## Controller Review

`AttachmentDownloadController` safety audit:

| Concern | Verdict |
| --- | --- |
| Authenticated owner resolution | Uses `apiKeyOwner` + `resolveOwnedInbox` |
| Attachment belongs to visible owned email | `where('email_id', $ownedEmail->getKey())->whereKey($attachment)` |
| Filename not trusted from user input | Route UUID only; `safeFilename()` sanitizes stored `original_filename` |
| Path traversal impossible | `safeStoragePath()` rejects empty, NUL, absolute Unix/Windows, and `..` segments |
| Missing storage object | `Storage::…->exists()` required; else 404 |
| Download headers safe | Sanitized MIME, `nosniff`, `Content-Disposition: attachment`, bounded filename |
| No arbitrary disk selection | Disk must match `platform.storage.attachments_disk` |
| Size / Range gates | Oversized / negative size denied; `Range` → 416 |
| Scan/safety gate | `AttachmentVisibilityPolicy::view` requires clean + `is_safe` + private disk |
| No debug output / absolute path exposure | Stream only; no storage path in response headers/body |

No P0 authorization/path-traversal/storage findings identified for this scope.

---

## Contract Test Decision

`tests/Feature/InboxLifecycleApiContractTest.php` — **deferred**.

| Aspect | Detail |
| --- | --- |
| Exact attachment assertions | Endpoint matrix includes `GET …/attachments/{attachment}`; 401 loop; log count **10** |
| Also includes | Inbox CRUD + email list/show + read/unread |
| Patch-level staging safe? | **No** — single untracked file; cannot drop non-attachment endpoints without rewriting |
| Focused attachment test sufficient? | **Yes** — `AttachmentDownloadApiTest` covers download, ownership, scan states, missing file, expired inbox, unsafe path/filename |
| Deferral safer? | **Yes** |

**Contract test included: No.**

---

## Documentation Decision

`docs/API_REFERENCE.md` — **deferred**.

| Aspect | Detail |
| --- | --- |
| Dirty size | +131 / −2 |
| Mixed content | Auth wording, email list/show, read-state, **attachment download**, inbound webhook, lifecycle/ops, ClamAV limitation |
| Attachment section isolatable? | Conceptually “### Attachment download” exists inside a larger mixed addition |
| Whole-file staging unsafe? | **Yes** |
| Decision | Remain deferred (P3 documentation gap) |

**Documentation included: No.**

---

## Included Attachment API Files

Only the paths below may be staged for this change set.

| Path | Git status | Ownership | Purpose | Dependency | Staging method |
| --- | --- | --- | --- | --- | --- |
| `app/Http/Controllers/Api/V1/AttachmentDownloadController.php` | `??` untracked | Attachment API controller | Owner-scoped streamed download with path/disk/scan safety | Committed `OwnedEmailVisibilityService`, `Attachment` model/relation, `AttachmentVisibilityPolicy`, platform attachments disk | **exact path** |
| `routes/api.php` | `M` modified | Attachment API route | Register download import + `GET …/attachments/{attachment}` under `inboxes:read` | Controller | **patch-level** |
| `tests/Feature/AttachmentDownloadApiTest.php` | `??` untracked | Attachment API test | Download success, cross-owner/scope denial, unsafe scan/missing file, expired inbox, traversal/filename rejection | Controller + route + committed visibility/policy | **exact path** |
| `docs/ATTACHMENT_DOWNLOAD_API_CHANGE_MANIFEST.md` | created by this prompt | Documentation (manifest only) | Staging/ownership record for this scope | — | **exact path** |

### Included counts

* Included Attachment API paths: **4**
* Attachment tests included: **1** (`AttachmentDownloadApiTest`)
* Shared/mixed files included: **1** (`routes/api.php`)

---

## Excluded Files

Explicitly exclude from this change set:

| Path / area | Reason |
| --- | --- |
| Inbox API controllers/requests/resources/tests | Inbox API — already committed; no restaging |
| Email read-state controller/resource/tests/manifest | Email read-state — already committed; no restaging |
| `app/Models/Attachment.php` / `app/Models/Email.php` | already-committed dependency |
| `app/Services/Email/OwnedEmailVisibilityService.php` | already-committed dependency |
| `app/Policies/AttachmentVisibilityPolicy.php` | already-committed dependency |
| `tests/Feature/InboxLifecycleApiContractTest.php` | mixed contract test — deferred |
| `docs/API_REFERENCE.md` | mixed documentation — deferred |
| Inbound processing/metrics (webhook, ingest/replay, jobs, Filament failures, inbound metrics, …) | inbound processing |
| ClamAV scanner services/health/tests, dirty `config/attachments.php` ClamAV keys, `docs/CLAMAV_INTEGRATION_TESTING.md` | ClamAV |
| Relational concurrency harness/tests/docs | relational concurrency |
| `.github/workflows/tests.yml`, `phpunit.xml`, `docker-compose.test.yml` | CI |
| `.env.example` | unrelated |
| `bootstrap/app.php` | unrelated |
| `storage/test-results/**` | generated artifacts |
| Prior committed process/lifecycle/API files | already committed; do not restage |

---

## Safety Rule

```text
Only files explicitly listed in the Included Attachment API Files section may be staged for this change set.
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
| F1 | **P2** | `routes/api.php` remaining dirty hunks are attachment-only vs HEAD, but whole-file staging would still be wrong practice and must stay prohibited; patch-stage import + route only. |
| F2 | **P2** | `InboxLifecycleApiContractTest` mixes Inbox, read-state, and attachment with hard-coded log count 10. Defer. |
| F3 | **P2** | `docs/API_REFERENCE.md` mixes attachment docs with read-state/inbound/ops. Defer. |
| F4 | **P3** | Attachment download API docs remain a documentation gap until a later docs prompt. |
| — | **P0** | None — controller ownership, traversal, disk, missing-file, and header gates look sound. |
| — | **P1** | None — `Attachment::email()` relation and visibility dependencies already available. |

**P0/P1/P2/P3:** P0=0, P1=0, P2=3, P3=1

---

## Final Decision

| Decision | Value |
| --- | --- |
| Required ownership dependency already committed | **Yes** |
| Required attachment relation available | **Yes** |
| Controller included | **Yes** |
| Focused test included | **Yes** |
| `routes/api.php` independently patch-stageable | **Yes** |
| Contract test included | **No** |
| Documentation included | **No** |
| Scope internally complete | **Yes** |
| Ready for staging-plan validation | **Yes** |
| Highest-priority next single prompt | **Prompt 465 — Attachment Download API staging-plan validation** (exact-path + `routes/api.php` patch plan; no commit) |

---

## Command Evidence Snapshot

* `git diff --check`: **clean** (exit 0)
* Staging performed: **No**
* Commit: **None**

### Inspected candidates / discoveries

`AttachmentDownloadController`, clean `Attachment`/`Email` models, committed `OwnedEmailVisibilityService` + `AttachmentVisibilityPolicy`, remaining `routes/api.php` attachment import/route only, `AttachmentDownloadApiTest`, mixed contract test, mixed `docs/API_REFERENCE.md`, ClamAV-only dirty `config/attachments.php` (excluded).
