# Commercial Usage Summary

Prompt 634 centralizes remaining quota calculation, owner-visible usage reporting, unified commercial denial envelopes, and threshold notifications.

## Remaining quota rules

Every finite numeric feature exposes:

```text
limit
used
remaining
```

Formula:

```text
remaining = max(limit - used, 0)
```

Fail-closed semantics:

- missing mapping → feature omitted from summary
- malformed or negative limit values → `0`
- unlimited catalogue values follow the existing `null` limit convention (`limit_value === null` on period meters)

Inventory counters:

| Feature | Used source |
| --- | --- |
| `max_api_keys` | non-revoked API keys |
| `webhook.max_endpoints` | active webhook endpoints |
| `inbox.max_active` | active, non-expired inboxes |

Period meters reuse `subscription_usage` rows:

| Feature | Used source |
| --- | --- |
| `outbound_messages_per_period` | current period `used_value` |
| `outbound_recipients_per_period` | current period `used_value` |
| `outbound_attachment_bytes_per_period` | current period `used_value` |

## Summary API

```http
GET /api/v1/commercial/usage
Authorization: Bearer <api-key>
```

Middleware: `api.scope:outbound_messages:read`, `api.entitlement:api.read`, `api.rate-limit`

Example:

```json
{
  "data": {
    "plan": "premium",
    "subscription_status": "active",
    "upgrade_required": false,
    "recommended_plan": null,
    "features": {
      "outbound_messages_per_period": {
        "limit": 1000,
        "used": 121,
        "remaining": 879,
        "unlimited": false,
        "reset_at": "2026-08-01T00:00:00+00:00"
      },
      "max_api_keys": {
        "limit": 10,
        "used": 2,
        "remaining": 8,
        "unlimited": false,
        "reset_at": null
      }
    }
  }
}
```

Only user-visible finite features from `config/commercial.php` are returned.

## Unified commercial responses

All commercial denials flow through `CommercialResponseFactory`.

Feature denial:

```json
{
  "error": {
    "code": "feature_not_available",
    "message": "Your current plan does not include API write access.",
    "details": {
      "feature": "api.write",
      "upgrade_required": true,
      "recommended_plan": "premium"
    }
  }
}
```

Quota exhaustion:

```json
{
  "error": {
    "code": "plan_limit_reached",
    "message": "Your current plan limit has been reached.",
    "details": {
      "feature": "webhook.max_endpoints",
      "limit": 10,
      "used": 10,
      "remaining": 0,
      "upgrade_required": true,
      "recommended_plan": "premium"
    }
  }
}
```

Rate limit:

```json
{
  "error": {
    "code": "rate_limit_exceeded",
    "message": "Too many API requests. Please try again later.",
    "details": {
      "feature": "api.max_requests_per_minute",
      "limit": 20,
      "remaining": 0,
      "upgrade_required": true,
      "recommended_plan": "premium"
    }
  }
}
```

No pricing metadata is exposed. UI copy may reference the recommended plan slug only.

## Threshold notifications

Configured thresholds (`config/commercial.php`):

```text
80%
90%
100%
```

`CommercialThresholdNotificationService` emits one audit event per threshold crossing using a cache idempotency key. Outbound message quota also reuses `OutboundNotificationService` warning/exhausted events.

## Dashboard integration

The session mailbox UI reads `CommercialUsageSummaryService` directly and renders a lightweight quota banner. API clients should prefer `GET /api/v1/commercial/usage` rather than recomputing limits locally.

## Legacy endpoint

`GET /api/v1/outbound-usage` remains available for outbound-only clients. New integrations should use the unified commercial summary.
