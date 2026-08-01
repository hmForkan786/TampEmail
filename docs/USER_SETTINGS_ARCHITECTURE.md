# User Settings Architecture

Unified user-facing Settings Center for Temail. UI is a thin control surface over existing Identity, Commercial, Billing, API-key, Affiliate, Audit, and Analytics services.

## Sections

| Section | Route | Authority |
|---|---|---|
| Overview | `/settings` | `UserSettingsSummaryService` |
| Profile | `/settings/profile` | `UserProfileSettingsService` |
| Security | `/settings/security` | `UserSecuritySettingsService` + Identity email/password |
| Sessions | `/settings/sessions` | `SessionManagementService` + login history |
| Notifications | `/settings/notifications` | `NotificationPreferenceService` |
| API Keys | `/settings/api-keys` | `SettingsApiKeyService` → `ApiKeyService` / rotate action |
| Billing | `/settings/billing` | Billing/Subscription/Commercial usage services |
| Privacy | `/settings/privacy` | `PrivacyPreferenceService` |
| Account | `/settings/account` | `AccountClosureService` via summary service |
| Affiliate (optional) | `/settings/affiliate` | Affiliate dashboard + encrypted payout update |

## Persistence

- Core identity fields remain on `users`
- Typed `user_notification_preferences` (`user_id`, `category`, `channel` unique)
- Typed `user_billing_preferences` (metadata only; encrypted tax identifier)
- `user_privacy_exports` foundation (async archive, expiry)
- Marketing consent remains on `identity_preferences` (+ source/policy version)

No generic unrestricted key/value settings table is used for client-writable keys.

## Non-goals

MFA, OAuth/SSO, org/team settings, tax engine, payment method vault, refund controls, full GDPR automation, theme builder.
