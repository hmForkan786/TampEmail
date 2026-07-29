# Security Operations Runbook

Operational security procedures for the Temail platform. Certification: `PLATFORM_SECURITY_AUDIT.md`. Related: `WEBHOOK_OPERATIONS_RUNBOOK.md`, `CLAMAV_OPERATIONS_RUNBOOK.md`, `BILLING_WEBHOOK_INCIDENT_RESPONSE.md`.

## Quick reference

| Control | Mechanism |
| --- | --- |
| Login throttle | `throttle:login` — `RATE_LIMIT_LOGIN_PER_MINUTE` (default 5) by email\|IP |
| API throttle | `api.rate-limit` + commercial RPM |
| Inactive web users | `web.active` middleware |
| Inactive API users | `AuthenticateApiKey` → 403 |
| Security headers | `ApplySecurityHeaders` / `config/security.php` |
| Audit | `AuditLogWriter` + security/audit log channels |

## Account lifecycle

### Suspend / ban a user

1. Filament Users → Change status (or `ChangeUserStatusAction`)
2. Effect:
   - All non-revoked API keys revoked
   - `remember_token` cleared
   - Next web request under `auth`+`web.active` logs out and invalidates session
3. Audit: `user.status_changed`

### Compromised API key

1. Revoke key in Filament (or rotate)
2. Issue replacement with least privilege scopes
3. Review `api_request_logs` for the `key_prefix` window
4. If owner compromised → suspend user

### Compromised webhook secret

1. `POST /api/v1/webhooks/{id}/rotate-secret` (owner) or disable endpoint
2. Customer updates verifier to new secret
3. Old signatures fail immediately after rotation

### Compromised SMTP / outbound credentials

1. Rotate provider credentials in env
2. `php artisan config:cache` (or restart workers)
3. Confirm `outbound:status` / test send in canary
4. Emergency stop if needed: `OUTBOUND_EMERGENCY_STOP=true`

### Compromised `APP_KEY` / `API_KEY_HASH_SECRET`

1. Treat as full credential compromise
2. Rotate `APP_KEY` only with a planned re-encrypt of encrypted columns (do not casually change)
3. Rotating `API_KEY_HASH_SECRET` invalidates **all** API key hashes — re-issue every key
4. Invalidate sessions (flush session store) after APP_KEY change

### Malware / infected attachment

1. Confirm `infected` in Quarantined Attachments
2. Do not download to operator workstation without isolation
3. Purge via admin action if policy allows (hold-aware)
4. Review ClamAV signatures freshness (`attachments:scanner-health`)

## Abuse / flooding

| Symptom | Action |
| --- | --- |
| Login 429 storms | Expected; review IP; adjust `RATE_LIMIT_LOGIN_PER_MINUTE` only carefully |
| API 429 | Check key RPM + commercial entitlement |
| Inbound webhook flood | Provider+IP limiter; block abusive IP at edge |
| User webhook retry storm | Disable endpoint; fix destination; max attempts terminal |
| Outbound abuse | `OutboundRateLimiter` / launch emergency stop |

## SSRF / XSS notes

- User webhook URLs: HTTPS + public DNS only; private ranges blocked at register and deliver
- Never enable HTTP redirects on webhook HTTP client
- Display paths must keep sanitizer before unescaped HTML

## Logging hygiene

Never log: passwords, API plaintext keys, webhook secrets, raw MIME bodies, attachment bytes, payment provider secrets.

Channels: `config('platform.logs.security_channel')`, audit channel, `api_request_logs`.

## Dependency advisories

Before each release:

```bash
composer audit
```

Record findings; schedule upgrades outside Prompt 658 scope. Current known follow-up: Guzzle medium advisories when locked below 7.15.1.
