# API Platform & Webhook Audit (Prompt 657)

Verification-first production audit of the external integration boundary: REST `/api/v1`, API-key authentication, scopes, rate limits, idempotency, user webhook delivery, and provider webhook verification. Architecture unchanged: no GraphQL, OAuth, API redesign, SDK generation, or billing redesign.

**Audit date:** 2026-07-29  
**Branch:** `feature/billing-payments`  
**Baseline HEAD:** `1f94c96` (after Prompt 656)  
**Final decision:** **PASS** (with accepted limitations)

## Frozen architecture (verified unchanged)

```text
Client
  → API Key
  → API Middleware
  → Application
  → Webhook Queue
  → Signed Delivery
```

## Authoritative components

| Concern | Implementation |
| --- | --- |
| Routes | `routes/api.php` (`/api/v1`) |
| Auth | `AuthenticateApiKey`, `ApiKeyResolver`, hashed `te_live_` tokens |
| Scopes | `ApiKeyScope`, `RequireApiKeyScope`, `ApiKeyScopeRegistry` |
| Entitlement | `EnsureCommercialApiEntitlement` |
| Rate limit | `ThrottleApiKey` + commercial `api.max_requests_per_minute` |
| Errors | `ApiErrorResponse` envelope |
| User webhooks | `WebhookEndpointController`, `DeliverWebhookJob`, HMAC signer/validator |
| Provider inbound | `InboundWebhookController` |
| Provider outbound | `OutboundWebhookController` (generic HMAC / SES SNS) |
| Billing callbacks | `PaymentProviderCallbackController` (public, provider-verified) |

Related: `API_REFERENCE.md`, `API_CONVENTION.md`, `API_COMMERCIAL_ENTITLEMENT.md`, `USER_WEBHOOKS.md`, billing webhook docs.

---

## 1. API versioning — PASS

- Authoritative surface: **`/api/v1`** only (`Route::prefix('v1')`)
- Named routes `api.v1.*`; no v2
- Public (no API key): inbound webhook, outbound provider webhooks, billing provider callbacks
- Deprecation: none required; v1 is current contract

---

## 2. Authentication — PASS (hardened)

| Check | Result |
| --- | --- |
| Format | `te_live_` + 43 chars |
| Lookup | prefix (16) + HMAC-SHA256 hash (`API_KEY_HASH_SECRET`) |
| Revocation / expiry | `revoked_at` / `expires_at` in active lookup |
| Ownership | Eager-loaded user; missing/soft-deleted → **401** |
| Suspended / banned / pending | **403** at `AuthenticateApiKey` (all `api.key` routes, including billing) |
| Fail closed | Empty hash secret / malformed token → reject |

**Prompt 657 harden:** inactive owners previously reached billing (`api.key` only). Auth middleware now fails closed before controllers.

---

## 3. Scope enforcement — PASS

Actual scopes: `inboxes:read|write`, `outbound_messages:read|write`, `mail_servers:read|write|admin`.

- Missing scope → 403
- Unknown/demoted stored scopes → fail closed
- No dedicated `webhooks:*` scope — webhooks reuse `outbound_messages:*` + `webhook.access` entitlement
- Billing customer routes intentionally omit scopes (owner auth + lifecycle only)

---

## 4. Authorization — PASS

Owner isolation via `apiKeyOwner` / `user_id` queries and visibility helpers. Foreign resources soft-404. Admin billing review paths require `isPlatformAdmin()` inside controllers. Mail servers are platform-global (operator/admin scopes).

---

## 5. REST contract — PASS

Success `{ data }`; errors `{ error: { code, message, details? } }`; pagination `page`/`per_page` with `meta`. Validation → 422. Consistent across inboxes, emails, outbound, webhooks, billing.

---

## 6. Rate limiting — PASS

Per-key throttle = min(abuse, commercial, key override). Billing checkout/callback have dedicated limiters. Provider ingress rate-limited by provider+IP. Anonymous without key → 401 (not anonymous API).

---

## 7. Idempotency — PASS

Inbound message-id uniqueness; outbound `(user_id, idempotency_key)`; user webhook `(endpoint_id, event_id)`; provider outbound replay fingerprint; billing checkout/ledger/webhook nonces. Replay-safe.

---

## 8. Webhook delivery — PASS

- Queue: `webhooks` (`queue.workloads.webhooks` / `QUEUE_WEBHOOKS`)
- App-level retries with exponential backoff + jitter; job `$tries=1`
- Timeouts from `config/webhooks.php`; redirects disabled
- Manual disable cancels pending; entitlement revoke cancels with zero HTTP
- No auto-disable on failure storms (accepted limitation)

---

## 9. Webhook security — PASS

HMAC-SHA256 over `timestamp.body`; encrypted secrets; rotation invalidates prior signatures; HTTPS-only destinations; SSRF checks (private/link-local/metadata blocked; DNS re-check before send). Residual DNS-rebinding TOCTOU accepted/mitigated.

---

## 10. Provider webhooks — PASS

| Ingress | Verification |
| --- | --- |
| Inbound | Provider HMAC + timestamp skew |
| Outbound/SES | Parser registry (generic HMAC / SNS signature) |
| Billing | Isolated provider verifiers (Stripe/SSLCommerz/…) |

Provider verification stacks remain isolated (no shared business logic leakage).

---

## 11. Queue — PASS

`DeliverWebhookJob` → `webhooks`; outbound events → `outbound-events`; inbound processing remains on default (accepted). Failed deliveries terminal after max attempts; unique delivery rows prevent duplicate fan-out.

---

## 12. Payload security — PASS

JSON validation on requests; inbound body size limits; outbound webhook body size/content-type allowlists; unknown fields generally ignored by FormRequests; UTF-8 safe headers/signatures.

---

## 13. API resources — PASS

Inboxes, emails, attachments, sender profiles, drafts, outbound messages, notifications, usage, webhooks, billing checkout/invoices — owner-scoped and envelope-consistent.

---

## 14. Commercial integration — PASS

`api.read` / `api.write` / `api.max_requests_per_minute` / `max_api_keys` / `webhook.access` / `webhook.max_endpoints`. Free typically write/webhook off. Downgrade/grace via entitlement service; delivery-time entitlement recheck.

---

## 15–18. Monitoring / scheduler / security

- API request logs; audit on webhook lifecycle; billing webhook prune command scheduled daily
- No user-webhook auto-prune sweeper beyond delayed retries (accepted)
- Key secrecy (hash-only storage); logging avoids secrets/payloads; SSRF + replay protections verified

Ops docs: `WEBHOOK_OPERATIONS_RUNBOOK.md`, `API_DEPLOYMENT_CHECKLIST.md`.

---

## Regression evidence (Prompt 657)

| Group | Result |
| --- | --- |
| Authentication / scopes / rate limit | passed (+ inactive owner on api.key-only) |
| Billing owner isolation + inactive checkout deny | passed |
| Webhooks delivery/security/endpoints/commercial | passed |
| Queue assignment for DeliverWebhookJob | passed |

### Tooling

| Check | Result |
| --- | --- |
| Pint | Changed PHP paths |
| PHPStan | Baseline pre-existing; harden path typed |
| Config / route cache | OK |
| Scheduler | `billing:prune-webhook-security` daily |
| `git diff --check` | Clean on audit artifacts |

---

## Accepted limitations

| Limitation | Notes |
| --- | --- |
| No dedicated `webhooks:*` API scope | Entitlement + outbound_messages scopes |
| Billing routes omit scopes/entitlements | Owner auth + lifecycle; admin checks in-controller |
| No auto-disable of failing endpoints | Manual disable / max attempts |
| Inbound jobs on default queue | Not isolated workload |
| DNS rebinding residual | Mitigated by re-resolve + no redirects |
| Doc drift in older API_CONVENTION | Outbound/billing exist; audit supersedes for readiness |

## Final decision

**API platform & webhook production ready: YES**

Fail-closed authentication now covers billing. Webhook HMAC/SSRF/retries and provider verification are production-grade under the frozen architecture. Remaining items are documented operational limitations, not isolation or fail-open defects.
