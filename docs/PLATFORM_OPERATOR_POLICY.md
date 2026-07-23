# Platform Operator Capability Contract

Status: implemented operator policy. Platform roles, live owner eligibility, MailServer scope gates, demotion revocation, audit requirements, and Filament admin authorization are implemented in the committed application paths and tests.

Related audits: Prompt 318 (operator-only MailServer scope issuance gaps). Related ownership: `docs/MAIL_SERVER_OWNERSHIP_POLICY.md`.

## Current contract boundary

The owner API is for user-owned product resources and does not expose public
API-key issuance, public customer billing/subscription management, or
SMTP/LMTP administration. MailServer catalog operations are an operator/admin
concern, not an ordinary owner capability. Platform-role state and runtime
gates are enforced by the committed User helpers, policies, scope registry,
API-key actions, and Filament panel authorization. Authorization fails closed;
no privileged capability is inferred from a pool entitlement or an opaque key
scope alone.

The Filament admin panel is an operational surface restricted to verified
active operators and admins; ordinary users are denied. Filament does not
create a super-admin bypass or bypass API ownership and audit boundaries.

## 1. Current implementation evidence

| Area | Evidence | Gap |
|---|---|---|
| `users` schema | Stores `platform_role` with `UserStatus` lifecycle | Unknown or inactive capability fails closed |
| `User` model | Provides active, non-deleted operator/admin helpers | Ordinary users are not operators |
| Filament | Admin panel access is gated by platform capability | Ordinary users are denied |
| API-key issuance/update | Scope registry and actions validate owner capability | Ineligible `mail_servers:*` grants are rejected |
| Auth middleware | Rechecks live owner eligibility and scope capability | Demoted owners fail closed |
| Audit log | Role changes, privileged grants, and revocation outcomes are recorded | Sensitive transitions remain attributable |

**Fail-closed rule:** missing or invalid operator capability is treated as **not an operator**. Pool entitlements (`mail_server_pools`) never imply operator capability. Public self-service key issuance, public billing APIs, and SMTP/LMTP administration remain unsupported.

## 2. Option comparison

### Option A — Boolean capability (`users.is_platform_operator`)

| Dimension | Impact |
|---|---|
| Migration | Single boolean, default `false` |
| Authorization | Simple `isPlatformOperator()` predicate |
| Scalability | Poor for distinguishing operator vs admin (`mail_servers:admin` vs read/write) |
| Demotion | Flip to `false`; must still invalidate privileged keys |
| Audit | Promote/demote boolean changes |

**Fit:** Smallest column, but cannot express the required admin-vs-operator split without a second flag or overloading “operator = full admin.”

### Option B — User platform role enum (`user` / `operator` / `admin`)

| Dimension | Impact |
|---|---|
| Migration | Single string column, default `user`, indexed |
| Authorization | Ordered capability: `admin` ⊃ `operator` ⊃ `user` |
| Scalability | Enough for current MailServer + Filament gates; can later map into full RBAC |
| Demotion | Downgrade role; revoke or runtime-deny privileged keys |
| Audit | Role transitions are explicit |

**Fit:** Matches architecture language (administrator vs ordinary user), enables `mail_servers:admin` only for `admin`, and stays package-free.

### Option C — Roles/permissions tables or package

| Dimension | Impact |
|---|---|
| Migration | Multiple tables + seeders, or composer package |
| Authorization | Fine-grained permissions |
| Scalability | Highest long-term |
| Demotion | Remove role assignments; still need key invalidation policy |
| Audit | Rich, but heavier than current phase needs |

**Fit:** Correct eventual destination for broad admin surfaces, but **overkill and out of scope** for closing the Prompt 318 blocker. No existing package is installed to extend.

## 3. Final recommended model

**Implemented model: Option B — `users.platform_role` enum** with values:

| Value | Meaning |
|---|---|
| `user` | Ordinary end-user (default) |
| `operator` | Verified platform operator (MailServer catalog read/write; Filament operator surfaces) |
| `admin` | Platform administrator (operator powers plus highest MailServer admin scope and broader platform controls as later prompts define) |

Runtime verification uses explicit helpers equivalent to:

```text
User::isPlatformOperator(): platform_role in {operator, admin} AND status === active AND not soft-deleted
User::isPlatformAdmin(): platform_role === admin AND status === active AND not soft-deleted
```

Missing column, null, unknown value, or inactive lifecycle → **fail closed** (treat as ordinary `user`).

The column, enum cast, helpers, and associated authorization paths are implemented.

## 4. Database and runtime contract

```text
Table: users
Column: platform_role  string  NOT NULL  default 'user'
Index:  users_platform_role_index (platform_role)
Optional composite index later: (platform_role, status)
```

Backward-compatible default:

- All existing rows receive `user` via column default / backfill.
- No user becomes operator by migration alone.
- Soft-deleted users remain non-operators regardless of stored role.

Allowed values (application-validated enum, e.g. `PlatformRole`): `user`, `operator`, `admin`.

## 5. Authorization matrix

### 5.1 Verified operator identification

A **verified platform operator** is a User with:

1. `platform_role` ∈ {`operator`, `admin`}, and  
2. `status` = `active`, and  
3. not soft-deleted.

A **verified platform admin** additionally requires `platform_role` = `admin` under the same status rules.

### 5.2 Default user capability

New and existing users default to `platform_role = user`. They may hold product scopes such as `inboxes:*` when product APIs exist. They must not receive `mail_servers:*`.

### 5.3 Operator vs admin

| Capability | `user` | `operator` | `admin` |
|---|---:|---:|---:|
| Inbox product / pool entitlement use | yes (per plan) | yes | yes |
| `mail_servers:read` on owned API keys | no | yes | yes |
| `mail_servers:write` on owned API keys | no | yes | yes |
| `mail_servers:admin` on owned API keys | no | no | yes |
| Promote/demote other users’ `platform_role` | no | no | yes (future gated UI/API) |
| Filament `/admin` access (see §8) | no | yes | yes |

`mail_servers:admin` is reserved for **admin** only (highest approved capability). Operators do not receive the admin wildcard.

### 5.4 Scope eligibility (`mail_servers:*`)

| Scope | Eligible owner role |
|---|---|
| `mail_servers:read` | `operator`, `admin` |
| `mail_servers:write` | `operator`, `admin` |
| `mail_servers:admin` | `admin` only |

Issuance/update must reject any `mail_servers:*` scope when the key owner is not eligible. Unknown scopes must be rejected by a canonical allowlist (separate implementation prompt).

### 5.5 Suspended / banned / pending behaviour

| Status | Operator/admin recognition | Privileged API keys |
|---|---|---|
| `active` | Per `platform_role` | Per stored scopes **and** live owner eligibility |
| `pending` | Not an operator | Privileged scopes must fail closed |
| `suspended` | Not an operator | Privileged scopes must fail closed |
| `banned` | Not an operator | Privileged scopes must fail closed |
| soft-deleted | Not an operator | Authentication must fail |

Lifecycle demotion of status is equivalent to losing verified operator recognition even if `platform_role` remains `operator`/`admin` until cleaned up.

## 6. Promotion / demotion behaviour

### 6.1 Promotion

- Only a verified **admin** (or a break-glass bootstrap process documented in a later ops prompt) may set `platform_role` to `operator` or `admin`.
- Self-promotion is forbidden.
- Every promotion must write an `AuditLog` entry (actor, target user, old/new role, IP/user agent when available).

### 6.2 Demotion

- Downgrading `platform_role` to `user` (or `admin` → `operator`) must be audited.
- **After demotion, privileged keys must not remain usable** for MailServer (or other operator) scopes.

Required demotion side effects (implementation contract):

1. **Immediate runtime deny:** API auth/scope checks re-evaluate owner eligibility on every request (defense in depth).  
2. **Persistence hardening:** On demotion, revoke or strip `mail_servers:*` (and future operator-only scopes) from the user’s API keys in the same transaction as the role change, **or** mark keys revoked so `ApiKeyResolver` cannot authenticate them.  
3. Prefer explicit revoke of keys that contain any privileged scope when role falls below eligibility, to avoid latent tokens.

Leaving privileged scopes stored while only relying on forgotten middleware checks is **non-compliant** with this policy.

### 6.3 Existing privileged keys after demotion

| Step | Behaviour |
|---|---|
| Role/status no longer verified operator/admin | Key must not authorize `mail_servers:*` routes |
| Preferred persistence action | Revoke key or remove ineligible scopes atomically with demotion |
| Soft grace period | Not allowed for MailServer admin scopes |

## 7. Filament panel access policy

The committed Filament gate:

- Only verified `operator` or `admin` users may access the `admin` panel (`canAccessPanel` or equivalent).
- Ordinary `user` accounts must be denied even if they somehow obtain panel credentials.
- Filament must not bypass MailServer API policy: catalog mutations remain operator/admin-bound and audited.
- This documentation prompt does not change Filament middleware.

## 8. API-key issuance / update gate

API-key enforcement in the Create/Update actions and related validation:

1. Resolve and lock the owning user.  
2. Validate permissions against a canonical allowlist.  
3. For each `mail_servers:*` permission, require owner eligibility per §5.4.  
4. Reject ownerless or inactive owners (existing ownership policy).  
5. Audit privileged scope grants (actor, owner, scopes).  

Direct Service/Action callers must hit the same gate—no bypass path for “internal” convenience without an audited admin actor.

Pool entitlement never authorizes these scopes.

## 9. Audit-log requirement

Minimum audited events:

| Action | Required |
|---|---|
| `platform_role` promote/demote | yes |
| Issue API key containing `mail_servers:*` | yes |
| Update API key adding `mail_servers:*` | yes |
| Demotion-triggered key revoke/strip | yes |

Use existing `audit_logs` table; do not log plaintext API tokens.

## 10. Implemented components

The committed implementation includes:

1. `users.platform_role` defaults to `user`.
2. `PlatformRole` and User cast/helpers are wired.
3. Existing rows default to `user`.
4. Issuance/update gates, auth re-checks, Filament gate, and tests are wired.

These paths are covered by focused authorization, revocation, audit, and Filament tests.

## 11. Safe defaults (normative)

- Existing users → ordinary `user`.  
- Missing/unknown capability → fail closed.  
- Suspended/banned/pending/soft-deleted → not operator.  
- Pool entitlement ≠ operator capability.  
- Demotion → privileged keys unusable (runtime + persistence).  
- `mail_servers:admin` → `admin` role only.  

## 12. Implementation sequence and maintenance

1. Migration: `platform_role` default `user` + enum.  
2. `User` helpers: `isPlatformOperator()`, `isPlatformAdmin()`.  
3. Canonical API scope allowlist.  
4. Gate `CreateApiKeyAction` / `UpdateApiKeyAction` (+ Service) for `mail_servers:*`.  
5. Defense-in-depth: MailServer scope middleware or context checks owner eligibility live.  
6. Demotion service: role change + revoke/strip privileged keys + audit.  
7. Filament `canAccessPanel` for operator/admin.  
8. Feature tests for issuance denial, admin-only `mail_servers:admin`, demotion, suspended users.  

## 13. Why not Option A or C for the baseline

- **Not A:** Cannot reserve `mail_servers:admin` for a higher tier without a second concept; architecture already distinguishes administrator-level control.  
- **Not C yet:** No package/tables exist; introducing full RBAC before closing the issuance hole delays the P0/P1 fix. Option B can later become a role assigned inside Option C without rewriting MailServer eligibility rules.
