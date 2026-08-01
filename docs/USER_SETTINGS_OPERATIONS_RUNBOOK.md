# User Settings Operations Runbook

## Health

```bash
php artisan settings:health
php artisan settings:health --json
```

Checks routes, notification registry, session enumeration compatibility, API-key/billing service availability, export storage, and stale/failed exports.

## Cleanup

Feature-flagged (`SETTINGS_PRUNE_ENABLED=true`):

```bash
php artisan settings:prune-expired-exports --dry-run
php artisan settings:prune-expired-exports --confirm
php artisan settings:expire-stale-email-changes --dry-run
php artisan settings:expire-stale-email-changes --confirm
```

Scheduler (when enabled in config):

- `settings:prune-expired-exports --confirm` daily 03:40
- `settings:expire-stale-email-changes --confirm` daily 03:50

Identity prune jobs remain authoritative for login-history/unverified retention. Settings email-change expiry only clears stale `pending_email` rows and does not duplicate Identity responsibilities.

## Recovery

| Symptom | Action |
|---|---|
| Export stuck pending | Re-dispatch `ProcessPrivacyExportJob` for the export id; inspect queue failures |
| Export download 404 | Confirm owner, status Ready/Downloaded, not expired, storage path exists |
| Session list empty | Confirm `SESSION_DRIVER=database` |
| API key create blocked | Check commercial `max_api_keys` entitlement and password confirmation |
| Marketing unexpectedly on | Check `identity_preferences.marketing_consent` and audit `settings.marketing_consent_updated` |

## Retention

- Login history: `IDENTITY_LOGIN_HISTORY_DAYS`
- Privacy exports: `SETTINGS_PRIVACY_EXPORT_TTL_HOURS`
- Stale pending email: `SETTINGS_EXPIRE_STALE_EMAIL_CHANGES_HOURS`
