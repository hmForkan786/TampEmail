# MailServer Ownership and Tenant Policy

Status: policy decision established by Prompt 317. Documentation only; no schema or runtime change in this prompt.

## 1. Current ownership model (evidence)

| Area | Finding |
|---|---|
| `mail_servers` schema | No `user_id`, `team_id`, tenant id, or ownership FK. Columns are operational: hostname, provider, protocol, pool, capacity, health. |
| `MailServer` model | Relationships: `inboxes()` only. No `belongsTo` User/Team/tenant. |
| Domain mapping | `Domain` has no MailServer FK or pool mapping. |
| Inbox relationship | `inboxes.user_id` nullable (user-owned or anonymous). `inboxes.mail_server_id` assigns infrastructure, not ownership of the server. |
| Selection | Authenticated: `MailServerSelectionService` resolves `mail_server_pools` entitlement, then locks an eligible global server by `pool_key`. Anonymous: `config('inbox.public_mail_server_pool')` exact match only. |
| API | `/api/v1/mail-servers` gated by API key + `mail_servers:read` / `mail_servers:write`. `mail_servers:admin` implies other `mail_servers:*` scopes. No ownership filter in controller/repository. |
| Filament | No MailServer Filament Resource exists yet. |
| Product docs | Spec/architecture describe MailServers as ingestion infrastructure; user ownership applies to inboxes, API keys, subscriptions—not servers. |

**Conclusion of current state:** MailServers are already treated as shared platform infrastructure. Pool access is entitlement- or config-controlled. There is no tenant ownership to enforce from schema.

## 2. Option comparison

### Option A — Platform-managed global MailServers

- MailServers are not user- or tenant-owned.
- CRUD stays admin/platform-scoped API (and future Filament admin).
- Users never “own” a server; plans grant which `pool_key` values they may consume.
- Inboxes remain user-owned (or anonymous); assignment to a MailServer is capacity/routing only.
- Fits current schema, selection, anonymous public pool, and capacity locking without migration.

### Option B — Tenant-owned MailServers

- Requires ownership columns, backfill, ownership-scoped API filtering, pool isolation per tenant, lifecycle/deletion rules, and authorization redesign.
- Conflicts with today’s global pool selection, shared capacity queries, and `PUBLIC_MAIL_SERVER_POOL` as sole public authority.
- No existing requirement or schema evidence mandates this for the current product phase.

## 3. Final policy decision

**Adopt Option A: platform-managed global MailServers.**

```text
MailServer = platform-managed global infrastructure
Inbox      = user-owned resource (nullable user_id for anonymous)
Pool access = entitlement-controlled (authenticated) or config-controlled (anonymous)
MailServer API = admin/platform capability only (not ordinary user product surface)
```

No contradictory requirement was found that forces Option B. The prior open wording in `docs/API_CONVENTION.md` (“ownership model undefined”) is closed by this decision.

## 4. API, entitlement, and Inbox relationship

```text
Platform operator (API key with mail_servers scopes)
  → CRUD MailServer records (global catalog)

User plan entitlement `mail_server_pools`
  → allowed pool_key list only
  → CreateInboxAction + MailServerSelectionService
  → assign mail_server_id on Inbox

Anonymous provisioning
  → PUBLIC_MAIL_SERVER_POOL / inbox.public_mail_server_pool
  → exact pool_key match only; never pool_key = null

Inbox
  → owned by user_id when authenticated
  → references MailServer as infrastructure assignment
```

Users must not list, create, or mutate MailServer records through ordinary inbox/API product scopes (`inboxes:*`). Discovering or administering hostname/provider/metadata is an operator concern.

## 5. Security impact

| Risk | Mitigation under Option A |
|---|---|
| Tenant data leak via MailServer list | Do not expose MailServer CRUD to end-user keys; treat `mail_servers:*` as operator scopes. |
| Inferring ownership from pool/hostname | Forbidden. Pool key is routing/entitlement metadata, not ownership. |
| Accidental public exposure of private pools | Anonymous flow uses only configured public pool; other pools remain entitlement-gated. |
| Capacity cross-talk | Shared infrastructure by design; isolation is via pools + entitlements + capacity limits, not tenant FKs. |
| Option B premature migration | Avoids unused ownership columns and false security assumptions until a real BYO-mail-server product exists. |

## 6. Documented rules (normative)

### 6.1 Ownership model

MailServers are platform-managed. There is no per-user or per-team owner on `mail_servers`.

### 6.2 API access rules

| Caller | MailServer read | MailServer write | Notes |
|---|---:|---:|---|
| Anonymous | deny | deny | No unauthenticated MailServer admin. |
| API key without `mail_servers:*` | deny | deny | |
| API key with `mail_servers:read` | allow (global catalog) | deny | Operator/platform keys only; not a user product grant. |
| API key with `mail_servers:write` | as needed for write | allow (global catalog) | Same operator boundary. |
| API key with `mail_servers:admin` | allow | allow | Implies other `mail_servers:*` scopes per existing context helper. |
| Ordinary user inbox scopes only | deny | deny | Inbox APIs must not proxy MailServer admin. |

Issuance of `mail_servers:*` permissions is an administrative act bound by `docs/PLATFORM_OPERATOR_POLICY.md`. Product plans must not attach these scopes to ordinary end-user API keys. Pool entitlements never imply operator capability.

Until the `platform_role` capability is implemented and issuance is gated, treat missing operator verification as **fail closed** for new privileged grants (Prompt 318 audit / Prompt 319 contract).

### 6.3 Entitlement vs ownership

- **Ownership:** who may administer the MailServer catalog → platform/operator.
- **Entitlement:** which pools a subscribed user may consume at inbox creation → `mail_server_pools` feature value.
- Entitlement never grants MailServer CRUD or visibility of unrelated pool servers’ admin fields beyond what inbox assignment requires.

### 6.4 Pool visibility rules

- Pool keys are opaque routing labels on global servers.
- Authenticated selection: exact membership in entitled pool list after normalization (trim, non-empty, unique).
- Servers with `pool_key = null` are not entitled for anonymous use and are not a default public fallback.
- Users do not browse the MailServer catalog to “see” pools; plans encode allowed pools.

### 6.5 Inbox ownership

- Inbox ownership is `inboxes.user_id` (null for anonymous).
- `mail_server_id` is an assignment to platform infrastructure.
- Deleting or reassigning a MailServer is an operator lifecycle concern and must not imply transfer of inbox ownership.

### 6.6 Admin / platform responsibilities

- Provision, health, capacity (`max_inboxes`), priority, pool labels, and activation.
- Configure `PUBLIC_MAIL_SERVER_POOL` for anonymous traffic.
- Attach plan features that grant pool keys.
- Operate Filament (when added) under the same capability boundary as the API—not a bypass of API policy.

### 6.7 Tenant isolation requirement

Under Option A, tenant isolation for mail routing is **pool- and entitlement-based**, not MailServer-row ownership. Message and inbox data isolation remain via Inbox/Email ownership and existing access rules. Cross-tenant MailServer row ownership is out of scope.

If a future product requires customer-managed (BYO) inbound servers, that is a **new** Option B track with explicit migration—not a silent reinterpretation of current rows.

### 6.8 Future migration (only if Option B is later approved)

1. Product decision for BYO / tenant-owned servers.
2. Ownership representation (`user_id` / `team_id` / tenant id) and nullability for legacy platform servers.
3. Backfill and dual-mode selection (platform pools vs owned servers).
4. Ownership-scoped API filtering, policies, and tests.
5. Pool isolation, deletion, and capacity semantics per owner.
6. Revise this document and `docs/API_CONVENTION.md` before implementation.

No migration is authorized by Prompt 317.

### 6.9 Relationship to anonymous public pool

Documented in `docs/ANONYMOUS_MAIL_SERVER_POOL.md`. Compatible with Option A: platform labels a dedicated global pool for anonymous traffic via config. Entitlement pools and public pool remain independent.

### 6.10 API scope matrix (summary)

| Scope | Role under this policy |
|---|---|
| `mail_servers:read` | Operator list/show of global MailServers |
| `mail_servers:write` | Operator create/update of global MailServers |
| `mail_servers:admin` | Operator super-scope for `mail_servers:*` |
| `inboxes:read` / `inboxes:write` | User inbox product surface; **not** MailServer admin |

## 7. Future implementation sequence (runtime follow-ups)

These are **not** part of this documentation prompt:

1. Treat MailServer API as operator-only in issuance/docs and any future policy class (no user-scoped filtering needed under Option A).
2. When Filament MailServer Resource is added, bind it to the same operator capability model.
3. Keep selection, capacity locking, and anonymous pool behaviour unchanged unless a separate prompt revises them.
4. Only open Option B after an approved product requirement and migration design.

## 8. Related documents

- `docs/API_CONVENTION.md` — API envelopes, scopes, and cross-reference to this policy.
- `docs/ANONYMOUS_MAIL_SERVER_POOL.md` — public pool configuration contract.
