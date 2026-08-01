# Privacy Export Policy

## Scope

Temail provides a **bounded** personal-data export foundation from Settings → Privacy.

### Included (when present)

- Profile fields
- Notification preferences
- Billing preferences (tax identifier not re-exported in plaintext beyond owner archive need; export uses billing preference summary masks where applicable)
- API-key metadata (id/name/prefix/scopes/timestamps) — **never secrets**
- Login-history metadata references via profile/security context
- Affiliate record presence summary through privacy center

### Deferred (explicitly not claimed complete)

- Inbox content bodies
- Full email bodies
- Full internal audit trails beyond safe disclosure

Do **not** claim GDPR automation or legal compliance certification from this foundation alone.

## Flow

1. Password-confirmed request
2. Rate-limited (`SETTINGS_PRIVACY_EXPORT_RATE_LIMIT_HOURS`)
3. Queued `ProcessPrivacyExportJob`
4. Private disk archive under `SETTINGS_PRIVACY_EXPORT_DIRECTORY`
5. Owner-only download
6. TTL expiry + prune command cleanup

## Security

- Owner isolation
- Private storage
- Audit: `settings.privacy_export_requested` / `settings.privacy_export_downloaded`
- Analytics: `settings.export_requested` (PII-safe)
- Notification when ready
