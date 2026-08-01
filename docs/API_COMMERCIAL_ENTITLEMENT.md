# API Commercial Entitlement

This document describes how commercial entitlements gate the public JSON API.

## Middleware

| Middleware | Alias | Purpose |
|------------|-------|---------|
| `EnsureCommercialApiEntitlement` | `api.entitlement` | Boolean feature checks (`api.read`, `api.write`, `webhook.access`, …) |
| `ThrottleApiKey` | `api.rate-limit` | Per-key request throttling |

Route groups apply middleware in this order:

1. `api.key` (authentication)
2. `api.scope:*` (credential scope)
3. `api.entitlement:*` (commercial feature)
4. `api.rate-limit`

Provider inbound/outbound webhooks (`/api/v1/inbound/webhook`, `/api/v1/webhooks/outbound/{provider}`) are intentionally outside user commercial middleware.

## API read/write rules

- Read routes require `api.read`.
- Mutating routes require `api.read` and `api.write`.
- Webhook mutation and delivery history require `webhook.access` in addition to the API read/write pair where applicable.

## API key counting

Active key slots are consumed only by **available** keys:

- not revoked
- not expired

`max_api_keys` is enforced inside `CreateApiKeyAction` under a user row lock.

## API key rotation

`RotateApiKeyAction` atomically:

1. locks the owner and source key
2. validates ownership and active state
3. creates a replacement key
4. revokes the source key
5. audits `commercial.api_key_rotated`

Rotation replaces one active key and therefore does **not** fail merely because the owner is already at `max_api_keys`.

## Effective rate limit

```text
effective limit = min(global route limit, commercial plan limit, per-key limit)
```

Per-key limits of `0` or `null` fall back to the global route limit.

Commercial values that are missing, null, malformed, or negative resolve to **0** and deny requests fail-closed with `rate_limit_exceeded`.

## Error contract

Feature denial:

```json
{
  "error": {
    "code": "feature_not_available",
    "message": "Your current plan does not include API write access.",
    "details": {
      "feature": "api.write",
      "upgrade_required": true
    }
  }
}
```

Limit denial:

```json
{
  "error": {
    "code": "plan_limit_reached",
    "message": "Your current plan limit has been reached.",
    "details": {
      "feature": "webhook.max_endpoints",
      "limit": 2,
      "used": 2,
      "remaining": 0,
      "upgrade_required": true
    }
  }
}
```

Rate denial:

```json
{
  "error": {
    "code": "rate_limit_exceeded",
    "message": "Too many API requests. Please try again later.",
    "details": {
      "feature": "api.max_requests_per_minute",
      "limit": 60,
      "remaining": 0,
      "upgrade_required": true
    }
  }
}
```

## Audit events

| Action | When |
|--------|------|
| `commercial.api_read_denied` | `api.read` middleware denial |
| `commercial.api_write_denied` | `api.write` middleware denial |
| `commercial.webhook_access_denied` | `webhook.access` middleware/service denial |
| `commercial.api_key_limit_reached` | API key quota exhausted |
| `commercial.api_key_rotated` | Successful API key rotation |
| `commercial.api_rate_limited` | Rate limit exceeded |

Audit payloads never include raw API keys, webhook secrets, authorization headers, or full request bodies.

## Test helpers

Shared helpers live in `tests/Support/commercial_api_helpers.php`:

- `grantApiRead($user)`
- `grantApiWrite($user)`
- `grantWebhookAccess($user)`
- `setApiKeyLimit($user, $limit)`
- `setApiRateLimit($user, $limit)`
- `setWebhookEndpointLimit($user, $limit)`
- `premiumWebhookFixture()`

Legacy API tests should grant only the entitlements required by the route under test. Do not bypass commercial middleware in feature tests.
