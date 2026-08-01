# Identity Operations Runbook

## Health

```bash
php artisan identity:health
php artisan identity:health --json
```

Checks registration mode validity, password broker, verification routes, queue, session driver compatibility, table presence, hash key, and operational metrics (pending verifications, open recoveries, failed logins/hour, invite backlog).

## Scheduled commands

| Command | Purpose | Flag |
|---------|---------|------|
| `identity:prune-login-history` | Delete aged login attempts | `IDENTITY_PRUNE_ENABLED` + `--confirm` |
| `identity:expire-invites` | Revoke expired invites | scheduler toggle |
| `identity:expire-recovery-requests` | Cancel stale recoveries | scheduler toggle |
| `identity:prune-unverified-users` | Soft-delete stale pending unverified | `IDENTITY_PRUNE_ENABLED` + `--confirm` |

All destructive prunes support `--dry-run`, are bounded by batch size, and use `withoutOverlapping` when scheduled.

## Common operations

### Open registration temporarily

1. Set `REGISTRATION_MODE=open` (or `invite_only`)
2. `php artisan config:cache`
3. Verify `identity:health` shows mode
4. Prefer invite-only for controlled launches

### Create invite

Use Filament **Identity → Identity Control → Create invite**. Raw token is shown once.

### Review recovery

1. Identity Control → Recovery requests
2. Start review → Approve/Reject
3. Complete only after approval (stages email if requested)
4. User must verify pending email and reset password

### Force session revoke

Identity Control action or `SessionManagementService::revokeAllForUser`.

## Metrics (safe)

Counters are derived from health/metrics and analytics `source_event` values without email/IP labels.

## Limitations

- Session enumeration requires `SESSION_DRIVER=database`
- Trusted devices foundation is disabled
- External CAPTCHA adapters are not shipped
- Self-service account restore is not supported
