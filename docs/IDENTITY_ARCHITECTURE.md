# Identity Architecture (Prompt 664)

## Purpose

Temail’s Identity Layer provides production-grade self-service registration, email verification, password recovery, admin-assisted account recovery, session management, and account closure — without redesigning billing, mail, API, affiliate, or analytics subsystems.

## Flow

```text
Registration
→ Email Verification
→ Login
→ Session Security
→ Password Recovery
→ Account Recovery
→ Commercial / Affiliate / Analytics hooks
```

## Components

| Area | Location |
|------|----------|
| Config | `config/identity.php` |
| Registration | `RegistrationService`, `RegisteredUserController` |
| Verification | `EmailVerificationService`, `EmailVerificationController` |
| Password policy | `PasswordPolicy` + `Password::defaults()` |
| Password reset | `PasswordResetService`, broker `users` |
| Sessions | `SessionManagementService` (database driver) |
| Recovery | `AccountRecoveryService`, `EmailChangeService` |
| Closure | `AccountClosureService` |
| Invites | `InviteService`, `RegistrationInvite` |
| Admin | Filament `IdentityControlPage` |
| Health | `php artisan identity:health` |

## Registration modes

| Mode | Behavior |
|------|----------|
| `disabled` (default) | Fail closed; safe redirect/message |
| `open` | Self-service allowed |
| `invite_only` | Valid invite token required |
| unknown | Fail closed to `disabled` |

## User status transitions

Existing enum: `pending`, `active`, `suspended`, `banned`, `closed`.

| Event | Transition |
|-------|------------|
| Register + verification required | → `pending`, `email_verified_at=null` |
| Register + verification disabled | → `active`, verified now |
| Email verified | `pending` → `active` |
| Suspend/ban (admin) | any → suspended/banned (keys revoked) |
| Self-service close | → `closed` |

Blocked statuses cannot verify into active access.

## Commercial integration

Registration does **not** create subscriptions, invoices, or paid orders. Free entitlements continue via `EntitlementService` canonical Free plan fallback (`slug=free`).

## Affiliate integration

After commit, registration calls `AffiliateAttributionService::linkUser`. Self-referral is invalidated. No commission is created at registration.

## Analytics integration

PII-safe events via `AnalyticsEventCollector` with `source_event` values such as `identity.registration_completed`. Raw passwords, tokens, verification URLs, and full IPs are never stored.

## Verified access policy

Middleware `identity.verified` (`EnsureVerifiedActiveUser`) gates product routes (inbox/outbound). Allowed before verification: login, verification notice/resend, logout, account security/sessions/recovery/close.

## Session policy

- Driver `database` supports enumeration/revocation/limits.
- Non-database drivers fail safely (empty list / no limit enforcement).
- `MAX_ACTIVE_WEB_SESSIONS=0` means unlimited.

## Exclusions (later prompts)

MFA, OAuth/OIDC/SSO, passwordless/magic links, KYC document processing, external CAPTCHA providers.
