# Versioned MailServer API Reference

Base URL: `/api/v1`

The endpoints below are the currently registered routes. This document describes the Laravel implementation, including its current limitations; it is not an OpenAPI contract.

## Authentication and authorization

Send:

```http
Authorization: Bearer <api-key-value>
Accept: application/json
```

The HTTP API has no token-issuance endpoint. API-key credentials are resolved by the existing first-party API-key middleware; credential values and stored hashes are never returned or logged.

Every endpoint requires an active, non-expired, non-revoked API key and a live owner. Suspended, banned, pending, soft-deleted, or otherwise invalid owners are rejected.

| Operation | Scope |
|---|---|
| List/show | `mail_servers:read` |
| Create/update | `mail_servers:write` |

An admin API scope may satisfy MailServer scopes according to the existing scope registry. MailServer access is restricted to platform operator/admin owners; ordinary user keys are not sufficient.

Authentication failures return `401`; valid keys without the requested scope return `403`.

## Response envelopes

Single resource responses use:

```json
{"data":{"id":"uuid","name":"Primary inbound"}}
```

Collections use:

```json
{
  "data": [],
  "meta": {"current_page":1,"per_page":15,"total":0,"last_page":1}
}
```

There is no `links` member in the implemented collection response.

Validation errors use:

```json
{
  "error": {
    "code": "validation_failed",
    "message": "The given data was invalid.",
    "details": {"name":["The name field is required."]}
  }
}
```

Other standard errors use the same `error.code` / `error.message` envelope. Common statuses are `401`, `403`, `404`, `422`, `429`, and `500`.

## Fields

Responses use snake_case and currently contain:

```text
id, name, hostname, provider, protocol, is_active, priority,
pool_key, max_inboxes, max_connections, timeout_seconds,
last_health_check_at, created_at, updated_at
```

`metadata` is never returned by the API resource, even when present in the database. This prevents arbitrary or future credential-bearing metadata from entering list, show, create, or update responses. `metadata` remains an accepted write-side field for existing validation compatibility, but clients must never send passwords, tokens, hashes, secrets, private keys, request bodies, or response bodies in it. `port`, when used by the application, is an operational metadata value; it is not a separate response property.

## Endpoints

### `GET /api/v1/mail-servers`

Scope: `mail_servers:read`.

Optional query parameters:

| Parameter | Behaviour |
|---|---|
| `provider` | Exact provider filter: `postfix`, `mailgun`, `ses`, or `smtp` |
| `protocol` | Exact protocol filter: `smtp`, `lmtp`, or `api` |
| `is_active` | Boolean active filter |
| `search` | Searches name and hostname |
| `per_page` | Page size; defaults to 15 |
| `sort_by` | Repository sort column input; default `created_at` |
| `sort_direction` | Sort direction input; default `desc` |

Returns `200` with the collection envelope. Pagination is Laravel paginator metadata only; links are not returned.

### `GET /api/v1/mail-servers/{mailServer}`

Scope: `mail_servers:read`.

`{mailServer}` is the MailServer UUID route binding. Returns `200` with one resource, or `404` when the record is not found.

### `POST /api/v1/mail-servers`

Scope: `mail_servers:write`. Returns `201` with one resource.

Request JSON fields:

| Field | Rules |
|---|---|
| `name` | required string, max 100 |
| `hostname` | required string, max 255 |
| `provider` | required: `postfix`, `mailgun`, `ses`, `smtp` |
| `protocol` | required: `smtp`, `lmtp`, `api` |
| `is_active` | optional boolean |
| `priority` | optional integer, minimum 0 |
| `max_connections` | optional integer, minimum 1 |
| `timeout_seconds` | optional integer, minimum 1 |
| `last_health_check_at` | optional nullable date |
| `metadata` | optional nullable array; never use for secrets |
| `pool_key` | optional nullable string, max 255, not whitespace-only |
| `max_inboxes` | optional nullable integer, minimum 1 |

The MailServer provisioning invariant additionally normalizes pool keys and accepts `null` as unlimited capacity.

### `PUT /api/v1/mail-servers/{mailServer}`

### `PATCH /api/v1/mail-servers/{mailServer}`

Both update routes use `mail_servers:write` and the same partial-update rules. Every field is optional; omitted fields remain unchanged. Nullable `pool_key`, `max_inboxes`, `last_health_check_at`, and `metadata` can be sent explicitly as `null` where supported by the request/DTO path. Returns `200` with one resource, or `404` for a missing UUID.

Update validation uses the same field types and enumerations as create, except fields are `sometimes` rather than required.

## Rate limiting

Rate limiting runs after authentication and scope authorization. The limit is first read from `ApiKey.rate_limit_per_minute`; invalid/non-positive values fall back to `config('abuse.rate_limits.api_per_minute')`, currently defaulting to 60 per minute. The window is 60 seconds.

Authenticated buckets are isolated by:

```text
api-key:{api_key_uuid}
```

The limiter uses Laravel's configured cache store; it does not hardcode Redis. Each counted response receives `X-RateLimit-Limit` and `X-RateLimit-Remaining`. A throttled response is `429` with `Retry-After` seconds:

```json
{"error":{"code":"rate_limit_exceeded","message":"Too many API requests. Please try again later.","details":{}}}
```

Revoked or expired keys fail authentication before the limiter. Scope-denied requests fail at `403` before the limiter. Successful and controller-error requests consume an attempt; a `429` response does not consume another attempt.

## Request logging and mutation audit

API requests are logged synchronously in `api_request_logs` before authentication middleware. Logs contain operational fields such as method, route name/path, status, duration, sizes, IP, API-key UUID, owner UUID, and a safe throttled flag. Missing/invalid credentials may have null API-key and owner fields. No Authorization header, plaintext token, hash, request body, response body, password, or secret is persisted.

MailServer create/update mutations separately write `mail_server.created` and `mail_server.updated` audit events in the same transaction as the mutation. Audit attribution uses the API-key owner and records the API-key UUID as safe metadata; Filament mutations use the authenticated platform user.

## Ownership and pool policy

MailServers are platform-managed global infrastructure and have no user/team owner. Inbox records are user-owned separately through `inboxes.user_id`. API scopes (`mail_servers:read/write/admin`) authorize the platform API surface; pool entitlements such as `mail_server_pools` are a separate product entitlement and do not grant API access. Anonymous provisioning, where enabled, uses the configured public pool policy and does not authenticate against these endpoints.

## Current limitations

- No HTTP API endpoint issues or rotates API keys.
- `metadata` is never exposed in API responses and has no public response schema. Its existing write-side acceptance remains for compatibility; clients must keep it non-sensitive.
- Pagination does not include `links`.
- Query parameter validation and sort-field allowlisting are limited by the current DTO/repository implementation.
- Request-log retention is governed by `docs/LOG_RETENTION_POLICY.md`; audit logs and API request logs have separate retention classes and legal holds.

## Inbound email API

These are the currently registered owner-scoped inbound routes. They use the API-key middleware, live owner capability checks, and the standard error envelope described above.

| Method | Route | Scope | Success |
|---|---|---|---|
| `GET` | `/api/v1/inboxes/{inbox}/emails` | `inboxes:read` | `200` collection |
| `GET` | `/api/v1/inboxes/{inbox}/emails/{email}` | `inboxes:read` | `200` resource |
| `GET` | `/api/v1/inboxes/{inbox}/emails/{email}/attachments/{attachment}` | `inboxes:read` | streamed binary |
| `PATCH` | `/api/v1/inboxes/{inbox}/emails/{email}/read` | `inboxes:write` | `200` email resource |
| `PATCH` | `/api/v1/inboxes/{inbox}/emails/{email}/unread` | `inboxes:write` | `200` email resource |

`{inbox}`, `{email}`, and `{attachment}` are UUID route parameters. An API key can access only inboxes whose `user_id` is the key owner's user ID. Anonymous inboxes are not owner-visible. Expired, inactive, soft-deleted, foreign, or missing inbox/email records resolve as `404`; platform operator/admin catalog capability does not grant global email access.

### Email list and show

`GET /api/v1/inboxes/{inbox}/emails` supports `page` and `per_page`; `per_page` is bounded to a maximum of 100 and defaults to 15. The collection envelope is:

```json
{
  "data": [],
  "meta": {"current_page": 1, "per_page": 15, "total": 0, "last_page": 1}
}
```

There is no top-level `links` member. A single email response uses `{"data": {...}}` and currently exposes email identity, sender/recipient display fields, subject, received time, optional body fields, safe visible attachments, and:

```json
{"is_read": false, "read_at": null}
```

Email body HTML is sanitized. Headers, raw MIME, internal metadata, processing logs, scanner details, and unsafe attachment records are not part of the response.

### Attachment download

`GET /api/v1/inboxes/{inbox}/emails/{email}/attachments/{attachment}` returns a streamed response only when all of these are true:

- attachment scan status is `clean`;
- `is_safe` is explicitly `true`;
- the configured attachment disk is private; and
- the private storage file exists and is within the configured attachment disk.

The response uses the stored validated MIME type, a sanitized original filename, `Content-Disposition: attachment`, `Content-Length`, and `X-Content-Type-Options: nosniff`. No public URL, signed URL, storage path, checksum, scanner metadata, or file bytes are included in JSON. `pending`, `scanning`, `failed`, `infected`, missing-file, unsafe-path, and oversized downloads are denied as `404`; range requests are rejected with `416`.

### Read state

`PATCH .../read` marks the owner-visible email as read and sets `read_at` to the current timestamp. `PATCH .../unread` clears both `is_read` and `read_at`. Both operations are idempotent and return the normal email resource envelope. They do not alter body, headers, attachments, MIME, or read-state audit payloads. Missing `inboxes:write` returns `403` before ownership lookup.
