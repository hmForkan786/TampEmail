# Inbox Lifetime and Renewal Policy

Status: product and security contract established by Prompt 405. Runtime renewal is **not** implemented by this document. Scheduled expiration (`config/inboxes.php` + `ExpireInboxesService`) and inbox provisioning remain unchanged.

## Decisions

| Question | Decision |
|---|---|
| Is inbox renewal supported? | **Yes**, as a future authenticated product capability. Runtime remains disabled until an implementation prompt enables it (`INBOX_RENEWAL_ENABLED`, default `false`). |
| User-owned vs anonymous lifetime | Both may receive a finite `expires_at` at creation. **Only user-owned** inboxes may renew. Anonymous renewal is never allowed. |
| Default lifetime | **24 hours** from creation (`INBOX_DEFAULT_LIFETIME_HOURS`, aligned with legacy `INBOX_DEFAULT_TTL=86400`). |
| Minimum lifetime | **1 hour**. |
| Maximum lifetime at creation | Capped by the effective absolute ceiling (platform config, optionally lowered/raised by plan entitlement within bounds). |
| Maximum extension per request | **24 hours** default (`INBOX_MAX_EXTENSION_HOURS_PER_REQUEST`, bounds 1–168). |
| Maximum absolute lifetime from creation | **720 hours (30 days)** default (`INBOX_MAX_ABSOLUTE_LIFETIME_HOURS`, bounds 24–8760). Measured from `inboxes.created_at`, not from “now”. |
| Plan/entitlement override | Optional feature key `inbox_max_lifetime_hours` (documented below). Absent/invalid → conservative configured absolute maximum. Never unlimited. |
| Expired inbox reactivation via renewal? | **No.** Expired inboxes cannot be revived by renewal. |
| Inactive / soft-deleted renewal? | **No.** |
| Renewal vs quota / capacity | Renewal does **not** create a new inbox and must **not** consume `max_inboxes` quota or MailServer capacity. It must not reassign `mail_server_id`. |
| Domain inactive | **Deny** renewal. |
| MailServer unavailable / full | **Allow** renewal of an already-assigned inbox; do not change MailServer assignment or require capacity headroom. |
| Concurrent renewal locking | Future implementation must `lockForUpdate` the inbox row inside a transaction before computing the new expiry. |
| Renewal audit event | `inbox.expiration_extended` (contract below). |
| Rate-limit / abuse | Dedicated per-owner renewal limit (`RATE_LIMIT_INBOX_RENEWAL_PER_HOUR`, default 10). Distinct from creation and global API limits. |

## Fail-closed defaults

These gates are hard-coded in `config/inbox_lifetime.php` and are **not** environment-overridable:

- Only **active**, **non-deleted**, **user-owned** inboxes are renewable.
- Expired and soft-deleted inboxes are never revived by renewal.
- Anonymous inbox renewal is disabled.
- Expiry may not be shortened.
- Absolute lifetime from `created_at` may not be exceeded.
- Renewal requires the authenticated API-key owner to match `inboxes.user_id`.
- Missing or invalid plan lifetime entitlement uses the conservative configured absolute maximum.
- Existing `mail_server_id` is preserved.

Numeric misconfiguration (out of bounds, zero, negative) fails closed to `0` for that setting, which makes renewal math unusable until fixed. `renewal_enabled` defaults to `false`.

## Eligibility matrix (future runtime)

| Condition | Renew? |
|---|---|
| `renewal_enabled=false` | Deny |
| Missing `inboxes:write` (or future dedicated scope, if introduced) | Deny |
| Inbox soft-deleted | Deny |
| Inbox `is_active=false` | Deny |
| `expires_at` null (permanent) | Deny (nothing to extend; permanence is not granted via renewal) |
| `expires_at <= now()` (expired) | Deny |
| `user_id` null (anonymous) | Deny |
| `user_id` ≠ API actor | Deny (404 ownership-safe) |
| Domain missing / inactive | Deny |
| MailServer unhealthy / full / missing | Allow (assignment unchanged) |
| Request would shorten `expires_at` | Deny |
| Request would exceed absolute ceiling from `created_at` | Deny |
| Extension exceeds per-request maximum | Deny |
| Owner over renewal rate limit | Deny (`429`) |

## Absolute ceiling calculation

```text
effective_max_hours = min(
  config('inbox_lifetime.max_absolute_lifetime_hours'),
  entitlement_limit_hours_if_valid_else_config_max
)

new_expires_at = current_expires_at + requested_extension
require new_expires_at > current_expires_at
require new_expires_at <= created_at + effective_max_hours
require extension_hours <= max_extension_hours_per_request
require extension_hours >= 1 (or equivalent non-zero forward delta)
```

If `max_absolute_lifetime_hours` or `max_extension_hours_per_request` is `0` (invalid config), renewal must fail closed.

## Entitlement

| Item | Value |
|---|---|
| Feature key | `inbox_max_lifetime_hours` |
| Value type | JSON object |
| Shape | `{"limit": <positive int hours>}` |
| `limit: null` / missing feature / missing key / non-int / out of platform bounds | Treat as **absent** → use configured `max_absolute_lifetime_hours` (conservative). **Not** unlimited. |
| Valid `limit` | Must satisfy `min_lifetime_hours ≤ limit ≤ max_absolute_lifetime_hours` from config bounds; otherwise treat as absent. |

This prompt does **not** seed the feature row, attach it to plans, or write runtime entitlement code. Issuance remains a later implementation concern. Do not invent alternate keys (`premium_ttl`, `INBOX_PREMIUM_TTL`, etc.) as entitlements.

Existing product entitlements remain authoritative for their domains:

- `max_inboxes` — creation count only; renewal does not consume it.
- `mail_server_pools` — authenticated provisioning selection only; renewal does not re-select pools.

## API contract proposal (not implemented)

```http
PATCH /api/v1/inboxes/{inbox}/expiration
Authorization: Bearer <api_key>
```

**Proposed scope:** `inboxes:write` (same write surface as create/delete until a dedicated scope is approved).

**Proposed JSON body (one of):**

```json
{ "extend_by_hours": 24 }
```

or

```json
{ "expires_at": "2026-07-24T12:00:00+00:00" }
```

Rules for a future implementation:

- `extend_by_hours` must be an integer within `1…max_extension_hours_per_request`.
- `expires_at` must be strictly after the current `expires_at`, within the per-request extension cap relative to the current expiry, and within the absolute ceiling from `created_at`.
- Shortening is rejected (`422`).
- Ownership failures return `404` (do not reveal foreign inboxes).
- Feature disabled or ineligible state returns `403` or `409` with a stable `error.code` (to be finalized at implementation).
- Success envelope: `{ "data": { …InboxResource fields… } }` with updated `expires_at` only among lifetime fields.
- No MailServer, pool, address mutation, or metadata passthrough.

## Audit contract

Proposed event name:

```text
inbox.expiration_extended
```

| Field | Source | Allowed |
|---|---|---|
| `audit_logs.user_id` | API-key owner | yes |
| `auditable` | Inbox model | yes (type + id only) |
| `old_values.expires_at` | prior expiry | yes (ISO-8601) |
| `new_values.expires_at` | new expiry | yes (ISO-8601) |
| `metadata.source` | `api` | yes |
| `metadata.api_key_id` | authenticating key UUID | yes |
| `metadata.changed_at` | mutation timestamp | yes |

**Must not** appear in old/new/metadata: inbox address, local-part, domain name, MailServer id, pool key, tokens, hashes, Authorization material, raw inbox `metadata`, request/response bodies, or headers.

Actor for scheduler expiry remains separate (`inbox.expired` with `source=scheduler`); renewal must not reuse that event name.

## Concurrent renewal locking

Future implementation lock order inside one DB transaction:

1. Resolve API owner (already authenticated).
2. `lockForUpdate` the target `inboxes` row by primary key under ownership + eligibility predicates.
3. Re-check eligibility on the locked row (active, not deleted, not expired, owned).
4. Compute and validate the new `expires_at`.
5. Persist `expires_at` only (no MailServer/address/ownership mutation).
6. Write `inbox.expiration_extended` in the same transaction.
7. Commit.

SQLite feature tests must not be claimed as relational concurrency proof; MySQL/PostgreSQL coverage remains required for lock races.

## Relationship to existing configuration (conflict audit)

| Existing item | Role today | Relationship to this policy |
|---|---|---|
| `INBOX_DEFAULT_TTL` / `INBOX_PREMIUM_TTL` (seconds in `.env.example`) | Legacy product hints; **not** wired into a config file today | Canonical hours keys are `INBOX_DEFAULT_LIFETIME_HOURS` and entitlement/`INBOX_MAX_ABSOLUTE_LIFETIME_HOURS`. Keep legacy vars until a wiring prompt migrates creation defaults; do not treat seconds TTL as renewal entitlement. |
| `StoreOwnedInboxRequest` max check | Uses `config('inboxes.max_lifetime_hours', config('inbox.max_lifetime_hours', 720))` — neither key currently exists, so create validation falls back to **720** | Matches this policy’s default absolute ceiling. A later prompt should read `config('inbox_lifetime.max_absolute_lifetime_hours')` without changing provisioning semantics in this prompt. |
| `config/inboxes.php` → `expiration.*` | Scheduler that deactivates/soft-deletes expired active inboxes | Orthogonal. Renewal extends `expires_at` before expiry; it does not replace `inbox.expired` processing. |
| `config/inbox.php` → `public_mail_server_pool` | Anonymous provisioning pool | Unchanged. Anonymous renewal remains forbidden. |
| `RATE_LIMIT_INBOX_CREATION_PER_HOUR` (`config/abuse.php`) | Creation abuse limit | Unchanged. Renewal uses `RATE_LIMIT_INBOX_RENEWAL_PER_HOUR` under `inbox_lifetime`. |
| Inbound message retention (`config/inbound_retention.php`) | Email/attachment retention after delivery | Unchanged. Inbox lifetime ≠ message retention. Expired inbox already blocks delivery per routing contract; stored messages follow retention/holds. |

## Configuration

| Item | Value |
|---|---|
| Config file | `config/inbox_lifetime.php` (sole runtime authority) |
| Config prefix | `inbox_lifetime.*` |

Runtime lifetime, renewal and expiration settings resolve only from this
canonical tree. Legacy environment keys are compatibility fallbacks only;
canonical values take priority. Invalid, zero, negative, non-numeric or
out-of-range values fail closed.
| Policy doc | `docs/INBOX_LIFETIME_POLICY.md` |

### Environment variables

| Variable | Default | Bounds / notes |
|---|---:|---|
| `INBOX_RENEWAL_ENABLED` | `false` | Feature gate for future endpoint |
| `INBOX_DEFAULT_LIFETIME_HOURS` | `24` | 1–168; invalid → `0` |
| `INBOX_MIN_LIFETIME_HOURS` | `1` | 1–24; invalid → `0` |
| `INBOX_MAX_EXTENSION_HOURS_PER_REQUEST` | `24` | 1–168; invalid → `0` |
| `INBOX_MAX_ABSOLUTE_LIFETIME_HOURS` | `720` | 24–8760; invalid → `0` |
| `RATE_LIMIT_INBOX_RENEWAL_PER_HOUR` | `10` | 1–120; invalid → `0` |

## Out of scope for this prompt

- Implementing `PATCH /api/v1/inboxes/{inbox}/expiration`
- Enabling the renewal feature flag in production
- Changing `ExpireInboxesService`, create/delete actions, or anonymous provisioning
- Seeding `inbox_max_lifetime_hours` into plans/features
- Reviving expired, inactive, or soft-deleted inboxes
