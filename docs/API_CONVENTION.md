# API conventions

This document defines shared protocol behavior for the implemented `/api/v1` owner API. Endpoint-specific fields and status details remain authoritative in [`API_REFERENCE.md`](API_REFERENCE.md).

## Prefix and authentication

API routes use the `/api/v1` prefix. Protected routes require:

```http
Authorization: Bearer <api-key-value>
```

The API-key middleware resolves the hashed key, rejects missing, invalid, revoked, or expired keys, and rejects inactive, suspended, banned, or soft-deleted owners. Plaintext keys are never logged or returned in errors. Public API-key issuance is not exposed through `/api/v1`.

The signed inbound webhook at `POST /api/v1/inbound/webhook` is intentionally outside API-key authentication and uses its provider signature contract instead.

## Scopes and middleware

Scope middleware runs after API-key authentication. Implemented scopes are:

| Scope | Routes |
|---|---|
| `mail_servers:read` | list/show platform MailServer records |
| `mail_servers:write` | create/update platform MailServer records |
| `mail_servers:admin` | administrative MailServer capability; satisfies other `mail_servers:*` checks |
| `inboxes:read` | read owned inboxes, emails, and attachment downloads |
| `inboxes:write` | create/delete/renew owned inboxes and change email read state |

Unknown or malformed scopes fail closed. `mail_servers:*` capabilities are operator/admin-gated; inbox scopes do not grant MailServer access. Rate limiting is applied to authenticated API routes after authentication and scope checks.

## Ownership and soft 404s

MailServers are platform-managed global infrastructure. Inboxes, emails, and attachments are owner-scoped. A foreign, inactive, expired, deleted, or otherwise inaccessible owner resource is intentionally returned as `404 not_found` rather than disclosed. Authorization failures before resource lookup may return `403 forbidden`.

## Fields and envelopes

External JSON fields use `snake_case`. Successful single-resource responses use:

```json
{"data": {"id": "uuid"}}
```

Collections use `data` plus pagination metadata:

```json
{"data": [], "meta": {"current_page": 1, "per_page": 15, "total": 0, "last_page": 1}}
```

Resources do not expose credentials, raw message content, internal metadata, or private attachment paths. The streamed attachment endpoint returns binary content only after owner visibility, email/attachment relationship, safe-download, and range preconditions pass. Range responses use the standard partial-content behavior documented in [`API_REFERENCE.md`](API_REFERENCE.md).

## Errors

Errors use a stable envelope:

```json
{"error": {"code": "validation_failed", "message": "The given data was invalid.", "details": {}}}
```

| Status | Meaning |
|---:|---|
| `401` | missing, invalid, revoked, or expired API key |
| `403` | authenticated key lacks the required scope or capability |
| `404` | absent or intentionally hidden resource |
| `422` | request validation failure; field details are included |
| `429` | API-key or route rate limit exceeded |
| `500` | safe generic server failure without internal exception text |

Validation details are field-keyed and use `snake_case`. Error bodies never contain bearer tokens, credentials, raw bodies, headers, attachment bytes, or stack traces.

## Pagination and filtering

Paginated endpoints accept `page` and endpoint-bounded `per_page` values. Inbox listing filters and sorts are restricted to the allowlist implemented by their request objects. Unsupported filters, sort values, or malformed pagination input fail with `422`; pagination metadata never includes top-level `links` unless an endpoint-specific contract explicitly adds them.

## MailServer boundary

MailServer records are global platform infrastructure, not tenant-owned resources. `mail_servers:read` and `mail_servers:write` are not ordinary inbox-user grants. Pool entitlements affect capacity/selection behavior and do not substitute for API scopes or operator capability.

## Unsupported public APIs

The public API does not expose:

- API-key issuance or management;
- customer billing or subscription APIs;
- native SMTP or LMTP endpoints;
- raw-MIME replay or arbitrary SMTP administration.

Filament and Artisan operations are separate administrative surfaces and do not expand the public API contract. The API reference is the authority for the currently registered endpoint set.
