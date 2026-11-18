# User Webhooks

User-owned outbound webhooks let API consumers register HTTPS endpoints that receive sanitized domain events.

## Ownership model

- Each `WebhookEndpoint` belongs to exactly one user.
- All CRUD, enable/disable, secret rotation, and delivery history routes are owner-scoped.
- Cross-user access returns `404` for unknown IDs.

## Endpoint slot semantics

`webhook.max_endpoints` counts **active/enabled** endpoints only.

| State | Consumes slot |
|-------|---------------|
| Active (`is_active = true`) | Yes |
| Disabled | No |
| Soft-deleted | No |

Creation and enable operations reserve a slot atomically under a user row lock.

## Routes

| Method | Path | Entitlements |
|--------|------|--------------|
| GET | `/api/v1/webhooks` | `api.read` |
| POST | `/api/v1/webhooks` | `api.read`, `api.write`, `webhook.access` |
| GET | `/api/v1/webhooks/{webhook}` | `api.read` |
| PATCH | `/api/v1/webhooks/{webhook}` | `api.read`, `api.write`, `webhook.access` |
| DELETE | `/api/v1/webhooks/{webhook}` | `api.read`, `api.write`, `webhook.access` |
| POST | `/api/v1/webhooks/{webhook}/enable` | `api.read`, `api.write`, `webhook.access` |
| POST | `/api/v1/webhooks/{webhook}/disable` | `api.read`, `api.write`, `webhook.access` |
| POST | `/api/v1/webhooks/{webhook}/rotate-secret` | `api.read`, `api.write`, `webhook.access` |
| GET | `/api/v1/webhooks/{webhook}/deliveries` | `api.read`, `webhook.access` |
| GET | `/api/v1/webhooks/{webhook}/deliveries/{delivery}` | `api.read`, `webhook.access` |

Plaintext secrets are returned only on create and rotate-secret responses.

## Supported event types

- `inbox.email.received`
- `outbound.message.sent`
- `outbound.message.delivered`
- `outbound.message.failed`
- `outbound.message.bounced`

## Payload schema

```json
{
  "schema_version": "2026-07-27",
  "event_id": "uuid",
  "event_type": "outbound.message.sent",
  "occurred_at": "2026-07-27T12:00:00+00:00",
  "data": {}
}
```

## Dispatch pipeline

```text
Domain event
→ WebhookDispatchService resolves active subscribed endpoints
→ delivery row created idempotently (unique webhook_endpoint_id + event_id)
→ DeliverWebhookJob queued after commit
```

Disabled, deleted, or unsubscribed endpoints are excluded.

## Delivery state machine

```text
pending → queued → delivering → delivered
                              → retry_scheduled → queued
                              → failed
pending/queued/retry_scheduled → cancelled
```

Invalid transitions fail closed.

## Retry policy

Retryable:

- connection timeouts / transient network failures
- HTTP 408, 425, 429
- HTTP 5xx

Terminal:

- entitlement revoked
- endpoint disabled/deleted
- unsafe URL at delivery time
- most other HTTP 4xx responses
- max attempts exceeded

Backoff uses bounded exponential delays with jitter. Application attempt accounting lives on the `webhook_deliveries.attempt_count` column; queue jobs do not multiply attempts.

## Signature verification

Headers:

- `X-Webhook-Id`
- `X-Webhook-Timestamp`
- `X-Webhook-Signature`
- `X-Webhook-Event-Type`
- `X-Webhook-Delivery-Id`
- `X-Webhook-Attempt`

Signing input:

```text
HMAC-SHA256(secret, timestamp + "." + raw_request_body)
```

Header value format: `sha256=<hex digest>`

## SSRF protections

- HTTPS only
- no embedded credentials
- DNS resolution must return publicly routable addresses
- private, loopback, link-local, metadata, and reserved targets rejected
- redirects disabled
- bounded connect/read timeouts
- bounded response excerpt persistence

URL safety is checked at registration and immediately before every delivery attempt.

## Secret lifecycle

- Secrets are stored encrypted at rest.
- Rotation generates a new cryptographically secure secret, persists it transactionally, and invalidates the previous secret immediately.
- Secrets are never returned from list/show/delivery history endpoints and are not written to audit logs.

## Entitlement revoke behavior

If `webhook.access` is revoked before delivery, the delivery transitions to `cancelled` with **zero HTTP** performed.

## Audit events

| Action | When |
|--------|------|
| `commercial.webhook_access_denied` | Webhook feature denied |
| `commercial.webhook_endpoint_limit_reached` | Endpoint slot exhausted |
| `commercial.webhook_secret_rotated` | Secret rotated |
| `commercial.webhook_delivery_cancelled` | Delivery cancelled before HTTP |
| `commercial.webhook_delivery_failed` | Terminal delivery failure |
| `commercial.webhook_delivery_succeeded` | Successful HTTP delivery |

## Error contract

Webhook feature and limit denials use the centralized commercial API envelopes documented in `docs/API_COMMERCIAL_ENTITLEMENT.md`.

Delivery cancellation is internal and is not exposed to unrelated API clients.
