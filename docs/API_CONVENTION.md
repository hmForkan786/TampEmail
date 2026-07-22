# API Convention

Status: proposed implementation convention, established by Prompt 293.

This document records the convention supported by the current codebase. It does not claim that the API is implemented.

## Evidence and decisions

The project is Laravel 12 with PHP 8.2 and Filament 5. `composer.json` does not include `laravel/sanctum` or another token-authentication package. `config/auth.php` defines only the `web` session guard. The `User` model is an authenticatable Eloquent model, and the users migration contains sessions and password-reset storage but no API-token storage.

The project already has an `ApiKey` model, migration, DTOs, actions, services, and repositories. API keys are hashed, owned by a user, optionally permission-scoped, revocable, expirable, and rate-limited. However, no middleware currently authenticates an API key, and no API routes or controllers exist.

Therefore:

- API routes will use `/api/v1`.
- The project API will use its existing first-party `ApiKey` domain rather than Sanctum, because no Sanctum package is installed and the existing domain already models the required credential lifecycle.
- Implementing the API-key guard/middleware, token issuance, permission checks, and migrations is deferred to a later implementation prompt.
- Sanctum installation is not part of this convention task. If the project later chooses Sanctum, this document must be revised before implementation.

The project specification requires versioned endpoints, explicit authorization, safe public errors, defined rate limits, and snake_case API JSON fields. Those requirements are authoritative for the conventions below.

## Authentication flow

Future requests should authenticate with:

```http
Authorization: Bearer <plain_api_key>
```

The future API-key middleware must:

1. Extract the bearer token without logging it.
2. Derive the stored key hash using the project’s API-key hashing policy.
3. Resolve an available key: not revoked and not expired.
4. Resolve the owning user and reject soft-deleted or inactive users.
5. Attach both the authenticated user and the authenticated API-key context to the request.
6. Record safe usage metadata only; never persist the plaintext token.
7. Apply the key’s configured rate limit together with the global `config/abuse.php` API limit.

Unauthenticated API routes are not permitted for MailServer administration.

## Token abilities and scope names

Use stable, lowercase, colon-delimited permission names stored in the existing `api_keys.permissions` JSON field:

| Scope | Meaning |
|---|---|
| `mail_servers:read` | List and show MailServer records visible to the caller |
| `mail_servers:write` | Create and update MailServer records visible to the caller |
| `mail_servers:admin` | Administrative MailServer operations, including cross-owner access if later approved |
| `inboxes:read` | Read owned inboxes |
| `inboxes:write` | Create/update owned inboxes |

`mail_servers:admin` does not implicitly grant unrelated module permissions. A future middleware/policy should allow `mail_servers:admin` wherever the corresponding read or write scope is required, or explicitly require both; that choice must be encoded in tests.

## Authorization and ownership

The current `mail_servers` table has no `user_id`, tenant identifier, or ownership relationship. Consequently, user ownership cannot currently be enforced from the schema.

Until an ownership model is introduced, MailServer endpoints must not be exposed as ordinary user-scoped endpoints. The safe implementation options are:

- expose them only to a separately authorized platform/operator context; or
- add an approved ownership/tenant design before exposing them to normal API users.

The future policy must never infer ownership from hostname, pool key, provider, or request input.

| Caller | MailServer read | MailServer write | Cross-owner access |
|---|---:|---:|---:|
| Anonymous | deny | deny | deny |
| Authenticated API key without scope | deny | deny | deny |
| Authenticated API key with `mail_servers:read` | allow only after ownership model is defined | deny | deny |
| Authenticated API key with `mail_servers:write` | read as required by write policy | allow only after ownership model is defined | deny |
| Authenticated API key with `mail_servers:admin` | allow | allow | only if operator/admin policy explicitly permits it |

## HTTP status and error responses

API JSON fields and error codes use `snake_case`.

MailServer API success and error envelopes are standardized (Prompt 302).

Success responses use a stable envelope:

```json
{
  "data": {
    "id": "uuid",
    "pool_key": "primary",
    "max_inboxes": 100
  }
}
```

Collection responses use `data` plus pagination `meta` (no top-level `links`):

```json
{
  "data": [],
  "meta": {
    "current_page": 1,
    "per_page": 15,
    "total": 0,
    "last_page": 1
  }
}
```

Errors use:

```json
{
  "error": {
    "code": "validation_failed",
    "message": "The given data was invalid.",
    "details": {
      "max_inboxes": ["The max inboxes field must be at least 1."]
    }
  }
}
```

| Status | Code | Use |
|---:|---|---|
| 401 | `unauthenticated` | Missing, invalid, expired, or revoked API credential |
| 403 | `forbidden` | Valid credential lacks required scope or ownership |
| 404 | `not_found` | Resource is absent or intentionally hidden by ownership policy |
| 409 | `conflict` | State or uniqueness conflict |
| 422 | `validation_failed` | Request validation failure (`error.details` is field-wise) |
| 429 | `rate_limit_exceeded` | API or key rate limit exceeded |
| 500 | `server_error` | Safe generic unexpected failure (no internal exception text in production) |

API-key middleware already returns the `error.code` / `error.message` envelope for 401/403 and must not be altered by resource formatting. Validation and not-found responses include `error.details` (empty object when unused). Plain API tokens must never appear in error bodies.

Do not reveal whether an inaccessible MailServer exists. Ownership failures should normally resolve as `404`; missing scope without resource lookup may resolve as `403`.

## Pagination and routes

Use Laravel pagination parameters `page` and `per_page`, bounded by the endpoint. Response metadata uses the envelope above.

Route names use dot notation and the versioned prefix:

```text
api.v1.mail-servers.index
api.v1.mail-servers.show
api.v1.mail-servers.store
api.v1.mail-servers.update
```

Future MailServer blueprint:

```text
GET    /api/v1/mail-servers
GET    /api/v1/mail-servers/{mail_server}
POST   /api/v1/mail-servers
PUT    /api/v1/mail-servers/{mail_server}
PATCH  /api/v1/mail-servers/{mail_server}
```

Use UUID route binding and never expose an unscoped model lookup before authorization.

## Field and DTO mapping

The external API follows the project’s documented snake_case convention:

```text
pool_key     → CreateMailServerData::poolKey / UpdateMailServerData::poolKey
max_inboxes  → CreateMailServerData::maxInboxes / UpdateMailServerData::maxInboxes
```

`max_inboxes: null` means unlimited capacity. An absent update field means “leave unchanged.” Explicit nullable clearing remains a separate DTO concern and must not be silently introduced by endpoint code.

MailServer API resources emit snake_case fields consistently, including `pool_key`, `max_inboxes`, `is_active`, `max_connections`, `timeout_seconds`, `last_health_check_at`, `created_at`, and `updated_at`. An audit found no frontend or internal API consumer depending on the previous camelCase response names, so this is a compatible standardization for the current project state.

## Controller data flow

Controllers must remain thin:

```text
API request
  → FormRequest validation
  → DTO::fromArray($request->validated())
  → Controller
  → MailServerService
  → MailServer Action
  → Repository
  → Database
  → MailServerResource
  → success envelope
```

Controllers must not implement pool selection, capacity calculation, persistence mapping, or authorization decisions. Authorization belongs in the future API-key middleware and policy/authorization layer.

## Filament alignment

Filament is an authenticated admin surface under the existing `admin` panel. A future MailServer Resource must use the same authorization capabilities and ownership rules as the API. It must not treat Filament access as permission to bypass API policy. Admin-only operations should use `mail_servers:admin` or the project’s eventual equivalent capability.

## Required follow-up implementation

Before Prompt 292 can safely add endpoints, the project needs:

1. API-key bearer authentication middleware/guard and request user context.
2. API-key issuance/revocation flow and secure hash verification policy.
3. Scope checking middleware or policy integration.
4. A decision and schema change for MailServer ownership or explicit platform-admin-only access.
5. Versioned API route registration and controller base response handling.
6. A resource implementation that emits snake_case fields, correcting any prior camelCase mapping if that mapping is not intentionally retained.
7. Feature tests for authentication, scope checks, ownership isolation, error envelopes, pagination, and rate limits.

No API route, controller, middleware, policy, token migration, or package installation is part of this document-only prompt.

## API-key generation format

Newly issued keys use `te_live_<base64url-random-secret>`, with 43 cryptographically random URL-safe characters. The stored `key_prefix` is the first 16 token characters. The stored `key_hash` is `v1:` followed by an HMAC-SHA256 digest of the complete plaintext token using the dedicated `API_KEY_HASH_SECRET` value.

`API_KEY_HASH_SECRET` is required; there is no `APP_KEY` fallback. Existing records are not rewritten and remain legacy/unresolvable until a separately approved compatibility policy exists. Plaintext is returned only through the creation-only issuance result and is never persisted, serialized from the model, or logged. Hash verification uses a timing-safe comparison.
