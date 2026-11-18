# Webhook Operations Runbook

Operational guide for user webhook delivery and provider webhook ingress. Architecture/audit: `API_PLATFORM_AUDIT.md`. Product contract: `USER_WEBHOOKS.md`. Billing provider callbacks: `BILLING_WEBHOOK_SECURITY.md`.

## Quick reference

| Command / queue | Purpose |
| --- | --- |
| `php artisan queue:work --queue=webhooks` | Deliver user webhooks |
| `php artisan queue:work --queue=outbound-events` | Outbound/SES provider events |
| `php artisan queue:work --queue=default` | Inbound message processing |
| `php artisan billing:prune-webhook-security` | Prune billing webhook replay/nonce rows |
| `php artisan outbound:confirm-ses-subscription` | Confirm cached SES SNS subscription |
| `php artisan schedule:list` | Verify prune schedule |

```env
QUEUE_WEBHOOKS=webhooks
```

## Flows

### User webhooks (egress)

```text
App event → WebhookDispatchService
  → WebhookDelivery row (unique endpoint+event)
  → DeliverWebhookJob (webhooks queue)
  → SSRF re-check → HMAC sign → HTTPS POST
  → delivered | retry_scheduled | failed | cancelled
```

### Provider webhooks (ingress)

| Endpoint | Auth |
| --- | --- |
| `POST /api/v1/inbound/webhook` | Inbound HMAC + timestamp |
| `POST /api/v1/webhooks/outbound/{provider}` | Generic HMAC or SES SNS |
| `POST /api/v1/billing/providers/{provider}/callback` | Provider verifier (no API key) |

## Common failures

### Symptom: User deliveries not sending

1. Worker must consume **`webhooks`** (not only `default`)
2. Endpoint `is_active=true` and `webhook.access` entitlement
3. Check delivery status: `queued` / `retry_scheduled` / `failed`
4. URL must pass HTTPS + SSRF validator

### Symptom: Retries exhausting → `failed`

- Defaults: max attempts 5; backoff ~30s, 120s, 600s + jitter
- Destination timeout / 5xx / connection errors retry
- 4xx (except configured retryables) may fail terminal
- Manual: disable endpoint, fix URL, enable, rotate secret if needed

### Symptom: Signature verification failures on customer side

Headers: `X-Webhook-Id`, `X-Webhook-Timestamp`, `X-Webhook-Signature` (`sha256=…`), event type, delivery id, attempt.

Canonical string: `timestamp + "." + raw_body` with endpoint secret.

After `rotate-secret`, old secret must be retired by the customer.

### Symptom: Inbound webhook 401 / 429 / 413

- Verify inbound secret and timestamp skew
- Rate limit key `inbound:{provider}:{ip}`
- Body size over `INBOUND_WEBHOOK_MAX_BODY_BYTES`

### Symptom: Outbound/SES events ignored

- Workers on `outbound-events`
- Signature invalid / stale → 401
- Unmatched events: `outbound:reconcile-unmatched-events`

### Symptom: Billing callbacks failing

- See `BILLING_WEBHOOK_SECURITY.md` / incident response
- Never disable signature verification in production
- `billing:webhook-verify` is prod-disabled by default

## Entitlement / disable policy

- Free plan typically lacks `webhook.access` → create denied; in-flight deliveries cancel with **zero HTTP**
- Soft-delete / disable endpoint cancels pending/queued/retry deliveries
- No automatic disable after N failures (operators disable manually)

## SSRF / destination rules

Allowed: publicly routable HTTPS URLs without userinfo.  
Blocked: http, private/link-local/metadata/CGNAT, DNS resolving to unsafe ranges.  
Redirects: disabled on delivery HTTP client.

## Monitoring signals

- Delivery failure rate / retry backlog on `webhooks` queue
- Audit: entitlement revoke, URL unsafe, secret rotation
- API request logs for webhook CRUD (no secrets)
- Billing prune command failures

## Safety rules

- Never log plaintext webhook secrets or full signed payloads in app logs
- Secrets stored encrypted; plaintext only on create/rotate response
- Provider verification stacks stay isolated (inbound ≠ user egress ≠ billing)
