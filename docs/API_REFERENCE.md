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

## Inbound webhook contract

The registered ingress route is:

```text
POST /api/v1/inbound/webhook
```

It is intentionally outside API-key authentication. The request must provide:

- `X-Inbound-Provider`, matching a configured provider;
- `X-Inbound-Timestamp`, a decimal Unix timestamp within the configured skew (default 300 seconds);
- `X-Inbound-Signature`, an HMAC-SHA256 signature over the provider, timestamp, and exact request bytes using the configured provider secret;
- `X-Inbound-Message-Id`, a non-empty provider message identifier; and
- a JSON body containing a non-empty `recipient`.

The webhook accepts the provider envelope and queues processing. It does not return email content or attachment content.

| Status | Meaning |
|---:|---|
| `202` | Envelope accepted for asynchronous processing |
| `401` | Missing, invalid, or expired signature/timestamp |
| `413` | Empty or oversized request body |
| `422` | Unsupported content type, missing message ID/recipient, or invalid received timestamp |
| `429` | Provider/IP ingress rate limit exceeded |
| `503` | Queue dispatch temporarily unavailable |

Error bodies use `error.code`, `error.message`, and an empty `error.details` object where applicable. Signature values, request bytes, and raw MIME are never included in errors or metrics.

## Inbound lifecycle and operations

Processing is asynchronous. The operational metrics service aggregates safe counters from lifecycle logs, attachment state, replay audit events, and bounded ingress counters over the last 5 minutes, hour, and 24 hours. The supported lifecycle codes are:

```text
received → queued → started → parsed → resolved → persisted
                                      ├─ duplicate
                                      ├─ rejected
                                      └─ failed
```

Attachment processing uses:

```text
pending → scanning → clean | infected | failed
```

Duplicate provider message IDs are idempotent and do not create a second email. Recipient resolution can reject an envelope without persisting an email. Retry exhaustion records a redacted failure/DLQ entry with stage, code, attempts, IDs, and timestamps only.

Replay is an admin operational action for eligible attachment-scan failures. It queues the scan job and records a safe audit event. Raw inbound MIME replay is unavailable because raw MIME is not retained. Replay availability does not expose a replay endpoint in this API reference.

### Health command

Run:

```text
php artisan inbound:health
```

The command prints JSON containing only status, breach names, thresholds, time windows, counters, latency aggregates, and backlog counts. It never prints message content, addresses, subjects, raw MIME, attachment bytes/paths, scanner output, exception traces, credentials, or hashes.

Configured thresholds are:

| Config key | Default |
|---|---:|
| `inbound_metrics.thresholds.failure_rate` | `0.10` |
| `inbound_metrics.thresholds.queue_backlog` | `100` |
| `inbound_metrics.thresholds.pending_scan_age_minutes` | `30` |
| `inbound_metrics.thresholds.retry_exhaustion` | `1` |

The command reports `healthy` when no threshold is breached and `degraded` otherwise. Metrics writes are best-effort and never block webhook acceptance, ingestion, scanning, or replay behavior.

Attachment scanner readiness is a separate operational command:

```text
php artisan attachments:scanner-health [--json]
```

It reports `healthy`, `disabled`, `degraded`, or `failed` without scanning or exposing attachment data. A disabled or unavailable scanner is not a clean result; attachments remain pending or follow the scanner retry/failure lifecycle.

### Rate limiting and request logging

Email and attachment API routes use the API-key-specific rate limiter after authentication and scope checks. Read-state routes use the same limiter with `inboxes:write`. The webhook has its own provider/IP limiter, defaulting to 60 requests per minute. API request logs retain method, route, status, duration, sizes, IP, API-key/owner IDs, and safe throttling metadata only. Authorization headers, credential values, hashes, bodies, raw MIME, and response payloads are not logged.

### Retention, legal holds, and scanner limitation

Inbound retention and cleanup remain governed by the existing inbound retention configuration and legal-hold behavior. Metrics do not change retention, cleanup, email ownership, or attachment quarantine rules. Audit logs and API request logs remain separate retention classes; existing legal holds continue to apply to their respective records.

The current default scanner backend is `disabled`. The committed scanner implementation supports ClamAV behind the configured scanner boundary, but this deployment must explicitly configure and verify an approved backend before attachments can become clean. A disabled or unavailable scanner is never treated as clean: attachments remain pending/retryable or reach a deterministic terminal failure. See [`CLAMAV_INTEGRATION_TESTING.md`](CLAMAV_INTEGRATION_TESTING.md) and [`PRODUCTION_RUNBOOK.md`](PRODUCTION_RUNBOOK.md) for enablement and operational requirements.

### APIs not exposed

This API reference does not define public API-key issuance or management; those operations are administrative and are not exposed as public `/api/v1` endpoints. Billing, customer subscription, SMTP-management, and raw-MIME replay endpoints are also not exposed. Do not infer these capabilities from internal services, admin pages, webhook processing, or operational commands.
