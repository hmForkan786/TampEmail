# Platform Security Audit (Prompt 658)

Cross-cutting security certification for the SaaS platform. Architecture unchanged: no OAuth/OIDC, identity-provider integration, encryption-engine replacement, framework replacement, or schema redesign.

**Audit date:** 2026-07-29  
**Branch:** `feature/billing-payments`  
**Baseline HEAD:** `3c043b0` (after Prompt 657)  
**Locked framework:** `laravel/framework` **v12.64.0** (`composer.lock`)  
**Final decision:** **PASS** (with accepted limitations)

## Frozen architecture (verified)

```text
Identity → Authentication → Authorization → Secrets → Data Protection
  → Application Security → Operations
```

## Summary of Prompt 658 hardenings

| Defect | Fix |
| --- | --- |
| Unthrottled `POST /login` | Named limiter `login` (email\|IP); `throttle:login` on route |
| Suspended web sessions usable | `EnsureActiveWebUser` (`web.active`) on authenticated web routes |
| Remember-me after suspend | `ChangeUserStatusAction` clears `remember_token` on non-active |
| Mail-server dynamic `ORDER BY` | Allowlisted columns/directions in `MailServerFiltersData` |

---

## 1. Authentication — PASS

| Check | Result |
| --- | --- |
| Web login / logout | Session regenerate on login; invalidate + regenerateToken on logout |
| Remember me | Supported; cleared on suspend/ban/pending |
| Inactive at login | Rejected after attempt; no session |
| Inactive mid-session | `web.active` logs out + invalidates session |
| API keys | Fail-closed (Prompt 657): soft-deleted → 401; non-active → 403 |
| Password reset | Migration exists; **no self-service reset UI** (accepted — accounts provisioned elsewhere) |

---

## 2. Authorization — PASS

Policies, Filament `canAccessPanel` (active privileged roles only), API scopes + entitlements, outbound/inbox/attachment/webhook ownership. No privilege-escalation path found in audited flows.

---

## 3. Session security — PASS (ops defaults)

| Flag | Implementation |
| --- | --- |
| HttpOnly | Default `true` |
| SameSite | `lax` |
| Secure | `SESSION_SECURE_COOKIE` (must be `true` behind HTTPS in prod) |
| Encrypt | `.env.example` prefers `SESSION_ENCRYPT=true` |
| Fixation | Session regenerate on login |

Concurrent sessions: multiple sessions allowed until logout/status change (accepted).

---

## 4. CSRF — PASS

Web POST/PUT/PATCH/DELETE use Laravel CSRF (Filament + Blade `@csrf`). Excluded by design: Bearer API, signed billing return, provider callbacks (signature-authenticated).

---

## 5. CORS — PASS (documented as framework-default)

No published `config/cors.php` allowlist. Browser SPA cross-origin is not a supported product surface; API is Bearer-oriented. **Accepted limitation** — publish CORS only if a browser origin is introduced.

---

## 6. Secrets management — PASS

`APP_KEY`, `API_KEY_HASH_SECRET`, webhook/billing/SMTP secrets via env; encrypted casts for webhook secrets and sensitive billing fields. Audit/API logs sanitize secrets. Rotation procedures in SECURITY runbook.

---

## 7. Encryption — PASS

Password hashing cast; API keys HMAC-hashed (never stored plaintext); encrypted Eloquent attributes; `Str::random` / token generators for secrets.

---

## 8. Audit logging — PASS

`AuditLogWriter` + `AuditPayloadSanitizer` deny password/token/secret/body keys. Covers status changes, API keys, outbound, webhooks, attachments, billing (as implemented).

---

## 9. Abuse protection — PASS

Login throttle; API key throttle + commercial RPM; inbox creation; inbound/outbound provider IP limits; billing checkout/callback; outbound send rate limiter. Webhook flooding bounded by delivery attempts + destination failures.

---

## 10. SSRF — PASS

`WebhookSecurityValidator`: HTTPS-only, no userinfo, blocks private/link-local/metadata; re-checked at delivery; redirects disabled. Residual DNS-rebinding TOCTOU documented (accepted).

---

## 11. XSS — PASS

Blade `{{ }}` escaping; `InboundHtmlSanitizer` at ingest; outbound content validation/sanitization before `{!! !!}`; filename sanitization on downloads; `X-Content-Type-Options: nosniff`. No CSP header (accepted limitation).

---

## 12. SQL injection — PASS

Eloquent/Query Builder dominant; bound `whereRaw` where used; list APIs validate sort allowlists; mail-server sort now allowlisted.

---

## 13. File handling — PASS

Reuse Prompt 656: private disk, opaque paths, traversal guards, clean-only download. See `ATTACHMENT_SECURITY_AUDIT.md`.

---

## 14. Dependency security — PASS (documented)

- Locked Laravel 12.x via `composer.lock`
- **Do not upgrade in this prompt**
- `composer audit` (2026-07-29): advisories reported for transitive `guzzlehttp/guzzle` (<7.15.1 medium issues). Record for follow-up upgrade track; not remediated here per scope

---

## 15. Configuration security — PASS (ops)

Production must set `APP_DEBUG=false`, `APP_ENV=production`, `SESSION_SECURE_COOKIE=true`, enable HSTS when HTTPS terminates at app. Trusted proxies/hosts: document in checklist; not hardcoded (accepted — environment-specific).

---

## 16. Security headers — PASS

`ApplySecurityHeaders`: `X-Content-Type-Options`, `X-Frame-Options: SAMEORIGIN`, `Referrer-Policy`, `Permissions-Policy`; optional HSTS via `SECURITY_HSTS_ENABLED`. **No CSP** (accepted).

---

## 17. Monitoring — PASS

Security/audit log channels; API request logs; inbound/outbound abuse metrics; Filament ops pages; billing webhook prune. No new SIEM.

---

## 18–19. Operations / incident recovery

See `SECURITY_OPERATIONS_RUNBOOK.md` and `SECURITY_DEPLOYMENT_CHECKLIST.md`.

---

## Regression evidence

| Group | Result |
| --- | --- |
| PlatformSecurityHardeningTest | 5 passed |
| PlatformFoundationTest | 4 passed |
| Auth/API/status/webhook/attachment suites | passed (focused) |

## Accepted limitations

- No password-reset UI / MFA / OAuth
- No CSP / published CORS allowlist
- TrustProxies/Hosts env-specific
- Concurrent web sessions until logout/status gate
- DNS rebinding residual on webhooks
- Dependency upgrades deferred (`composer audit` follow-up)
- No penetration test / external scan in scope

## Final decision

**Platform security production ready: YES**

Blockers (login throttle, inactive session use, remember-token after suspend, mail-server ORDER BY) are remediated. Remaining items are documented operational or out-of-scope limitations.
