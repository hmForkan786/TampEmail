# API Deployment Checklist

Pre-production checklist for the `/api/v1` platform and webhooks (Prompt 657). Complements `API_PLATFORM_AUDIT.md` and `WEBHOOK_OPERATIONS_RUNBOOK.md`.

## Prerequisites

- [ ] Migrations applied (`api_keys`, webhook endpoints/deliveries, billing webhook security tables)
- [ ] `API_KEY_HASH_SECRET` set to a long random secret (never empty)
- [ ] Commercial plan features seeded (`api.read`, `api.write`, `api.max_requests_per_minute`, `max_api_keys`, `webhook.access`, `webhook.max_endpoints`)
- [ ] HTTPS terminated in front of the app

## API keys

- [ ] Keys issued only via admin/Filament (no public key CRUD)
- [ ] Format `te_live_…`; only hashes stored
- [ ] Revocation and expiry paths tested
- [ ] Suspended/banned/pending owners rejected on **all** `api.key` routes (including billing)

## Middleware stack (scoped owner APIs)

```text
api.request-log → api.key → api.scope:* → api.entitlement:* → api.rate-limit
```

- [ ] Inbox/outbound/mail-server groups have scope + entitlement + rate limit
- [ ] Rate limit headers observed under load
- [ ] Cross-owner IDs return 404

## Billing API (customer)

- [ ] Checkout/orders/invoices behind `api.key` + billing throttle
- [ ] Owner isolation verified
- [ ] Inactive owners cannot checkout
- [ ] Admin crypto review requires platform admin
- [ ] Provider callback is public + provider-verified + `throttle:billing-callback`

## User webhooks

```env
QUEUE_WEBHOOKS=webhooks
```

- [ ] Worker consumes `webhooks`
- [ ] HTTPS + SSRF validation on create/update
- [ ] Premium (or entitled) plan for `webhook.access`
- [ ] Secret rotation smoke-tested
- [ ] Delivery retry/backoff config reviewed (`config/webhooks.php`)

## Provider webhooks

- [ ] Inbound secret + skew configured
- [ ] Outbound generic HMAC and/or SES SNS configured
- [ ] `outbound-events` worker running for provider events
- [ ] Billing webhook keys/verifiers configured per `BILLING_WEBHOOK_*` docs

## Workers / scheduler

- [ ] `webhooks`, `default` (inbound), `outbound-events`, `attachment-scanning`, notifications as required
- [ ] `billing:prune-webhook-security` scheduled daily
- [ ] `schedule:run` every minute

```bash
php artisan schedule:list
php artisan queue:work --queue=webhooks
```

## Security smoke test

1. Valid key + scope → 200 on entitled read  
2. Missing/malformed Bearer → 401  
3. Revoked/expired key → 401  
4. Suspended owner → 403 on scoped **and** billing checkout  
5. Missing scope → 403  
6. Foreign inbox/webhook/order → 404  
7. Free user webhook create → 403 entitlement  
8. Register `http://` webhook URL → 422  
9. Replay inbound/outbound provider webhook → idempotent / rejected stale  
10. Confirm API logs lack secrets and raw attachment bytes  

## Config / route cache

```bash
php artisan config:cache
php artisan route:cache
```

- [ ] Succeeds with production env  
- [ ] `git diff --check` clean on release branch  

## Post-deploy

- [ ] Hit `/api/v1` health-ish owner read with a canary key  
- [ ] Confirm `webhooks` queue depth drains  
- [ ] Confirm billing callback reachable only with valid provider signatures  
- [ ] Rotate one webhook secret and verify customer verification still works  

## Explicit non-goals

- OAuth/OIDC, GraphQL, API v2, SDK generation  
- Monitoring platform replacement  
- Billing provider redesign
