# Identity Deployment Checklist

## Pre-deploy

- [ ] `REGISTRATION_MODE=disabled` unless intentionally launching open/invite registration
- [ ] `REGISTRATION_EMAIL_VERIFICATION_REQUIRED=true` for public launch
- [ ] Password policy env reviewed (`PASSWORD_*`)
- [ ] `IDENTITY_HASH_KEY` set (or rely on `APP_KEY`)
- [ ] Mailer configured for queued identity notifications
- [ ] `SESSION_DRIVER=database` if session management UI is required
- [ ] `SESSION_ENCRYPT=true` (platform foundation requirement)
- [ ] Queue worker running for notification delivery
- [ ] Migrations applied: `2026_08_01_220000_create_identity_layer_tables`

## Deploy steps

```bash
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan event:cache
php artisan identity:health
php artisan schedule:list
```

## Post-deploy verification

- [ ] `GET /register` fails closed when disabled
- [ ] Login still works for active users
- [ ] Forgot/reset password flow with test mailbox
- [ ] Verification signed link works
- [ ] Filament Identity Control visible to platform admin
- [ ] Affiliate attribution still works on checkout
- [ ] API key auth unchanged
- [ ] `identity:health` OK

## Rollback

1. Set `REGISTRATION_MODE=disabled`
2. Config cache
3. Do not drop identity tables if recovery requests exist (retain for audit)

## CI notes

- SQLite in-memory is the default test DB
- Relational concurrency tests for identity races may skip unless `RUN_RELATIONAL_TESTS=1`
- Password uncompromised checks are disabled in identity feature tests
