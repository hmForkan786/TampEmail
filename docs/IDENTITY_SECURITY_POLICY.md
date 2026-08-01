# Identity Security Policy

## Password policy

Configurable via `config/identity.php` / env:

- Minimum length (default 12)
- Mixed case, number, symbol
- Uncompromised check (Have I Been Pwned) when enabled
- Laravel hashed password cast; never plaintext

Applied to registration and password reset. `Password::defaults()` is registered in `AppServiceProvider`.

## Email enumeration resistance

Generic responses for:

- Login failure
- Forgot password
- Verification resend
- Recovery submission
- Duplicate registration email (practical generic wording)

Anonymous users are never told “account exists but suspended.”

## Abuse protection

- Per IP + normalized email rate limits (registration, login, reset, resend, recovery)
- Honeypot field (value never stored)
- Minimum form-fill timing
- Optional `RegistrationChallengeVerifier` (disabled by default; no provider in Prompt 664)
- Invite failure bounded by validation + mode gating
- Suspicious registration audited (`identity.registration_blocked`)

## Tokens

- Verification: signed temporary URLs; expiry from `EMAIL_VERIFICATION_EXPIRE_MINUTES`
- Password reset: framework broker hashed tokens; expiry/throttle from env
- Invites: raw token shown once; `token_hash` stored (SHA-256)
- Never log raw tokens, verification URLs, or passwords

## Session security

- Session regenerated on login
- Password reset revokes other sessions by default
- Destructive session actions require current password confirmation
- Session IDs masked in UI; payloads never exposed

## Recovery security

- Admin-assisted only
- Sensitive fields encrypted (`new_email_encrypted`, `evidence_notes_encrypted`)
- Claimed email and IP stored as HMAC hashes
- Dual approval configurable for email-change recoveries
- Completion requires prior approval (cannot blindly mark completed)
- New email staged then verified before atomic replacement
- Password reset required after recovery completion
- Immutable append-only `review_history`

## Privacy

| Data | Handling |
|------|----------|
| Login history | Hashed email/IP/UA; retention `IDENTITY_LOGIN_HISTORY_DAYS` |
| Recovery evidence | Encrypted; admin masked in UI |
| Terms vs marketing | Separate fields/preferences — never combined |
| Closure | Status closed; financial/audit/affiliate ledger retained |
| Analytics | Deny-list strips email/password/ip/token keys |

## Incident response

1. Force session revoke (Filament Identity Control / `SessionManagementService`)
2. Force password reset path (clear remember token; user uses forgot-password)
3. Optional API key bulk revoke via existing repository
4. Suspend/ban via `ChangeUserStatusAction`
5. Review audit log actions prefixed `identity.*`
