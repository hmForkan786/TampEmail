# Security Deployment Checklist

Pre-production security checklist (Prompt 658). Complements `PLATFORM_SECURITY_AUDIT.md` and subsystem checklists (API, attachment, outbound, billing).

## Environment

- [ ] `APP_ENV=production`
- [ ] `APP_DEBUG=false`
- [ ] `APP_KEY` set and backed up securely
- [ ] `API_KEY_HASH_SECRET` long random; never empty
- [ ] HTTPS terminated correctly; app sees HTTPS (or TrustProxies configured)
- [ ] `SESSION_SECURE_COOKIE=true`
- [ ] `SESSION_ENCRYPT=true` (recommended)
- [ ] `SESSION_SAME_SITE=lax` (or `strict` if product allows)
- [ ] `SECURITY_HEADERS_ENABLED=true`
- [ ] `SECURITY_HSTS_ENABLED=true` only when HTTPS is guaranteed
- [ ] Queue/cache drivers not `sync`/`file` for multi-node prod without review

## Authentication / sessions

- [ ] `POST /login` has `throttle:login`
- [ ] `RATE_LIMIT_LOGIN_PER_MINUTE` reviewed (default 5)
- [ ] Authenticated web routes use `auth` + `web.active`
- [ ] Suspended user cannot keep browsing with old session
- [ ] Logout invalidates session + CSRF token

## Authorization

- [ ] Filament restricted to active operators/admins
- [ ] API scopes + entitlements on owner APIs
- [ ] Cross-owner resource IDs return 404

## Secrets

- [ ] No secrets in git; `.env` not committed
- [ ] Webhook/billing/SMTP/ClamAV secrets via env or encrypted DB columns
- [ ] Log redaction verified (no `te_live_` / passwords in audit samples)

## Abuse limits

- [ ] API RPM, inbox creation, ingestion, billing callback limits set
- [ ] Outbound rate / emergency stop understood
- [ ] Provider webhook IP limits enabled

## Headers / browser

- [ ] `X-Content-Type-Options: nosniff` present
- [ ] `X-Frame-Options: SAMEORIGIN` present
- [ ] Referrer-Policy / Permissions-Policy present
- [ ] HSTS present when enabled
- [ ] CSP deferred (accepted) unless product adds one

## Subsystem gates

- [ ] Attachment private disk + ClamAV enablement (`ATTACHMENT_DEPLOYMENT_CHECKLIST.md`)
- [ ] API/webhooks workers (`API_DEPLOYMENT_CHECKLIST.md`)
- [ ] Outbound fail-closed flags (`OUTBOUND_MAIL_DEPLOYMENT_CHECKLIST.md`)
- [ ] Billing webhook verification (`BILLING_WEBHOOK_SECURITY.md`)

## Verification commands

```bash
composer audit
php artisan config:cache
php artisan route:cache
php artisan schedule:list
php artisan platform:check
php artisan attachments:scanner-health --json
php artisan outbound:status --json
```

## Smoke tests

1. Failed login × N → 429  
2. Suspend user → API 403; web redirect to login  
3. CSRF missing on web POST → 419  
4. Foreign inbox/attachment/webhook → 404  
5. Webhook `http://` URL → 422  
6. Clean attachment download OK; infected → 404  
7. Security headers on `/`  

## Explicit non-goals

- Penetration test, WAF/CDN, SIEM, MFA, OAuth, dependency upgrades in this checklist pass
