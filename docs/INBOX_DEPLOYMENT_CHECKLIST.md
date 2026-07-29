# Inbox Deployment Checklist

Pre-production checklist for inbox lifecycle, expiry, retention, and commercial entitlements.

## Prerequisites

- [ ] Database migrations applied (`inboxes`, `inbound_holds`, etc.)
- [ ] Mail server pool configured with active servers
- [ ] Commercial plan features seeded (`inbox.create`, `inbox.max_active`, `inbox.retention_hours`, `inbox.custom_alias`, `mail_server_pools`)
- [ ] API key middleware and scopes enabled on inbox routes

## Inbox creation

- [ ] `PUBLIC_MAIL_SERVER_POOL` set for anonymous provisioning (if used)
- [ ] User mail server pools in plan entitlements (`mail_server_pools` JSON)
- [ ] Reserved local parts reviewed in `config/inbox.php`
- [ ] `inbox_lifetime.max_absolute_lifetime_hours` appropriate (default 720)

## Commercial entitlements

- [ ] Free plan: `inbox.max_active`, `inbox.retention_hours`, `inbox.custom_alias=false`
- [ ] Premium plan: higher limits documented
- [ ] Grace period behavior understood (Free entitlements during grace)

## Expiry scheduler

- [ ] `INBOX_EXPIRATION_SCHEDULER_ENABLED=true` in production
- [ ] `php artisan schedule:run` every minute
- [ ] Smoke test:

```bash
php artisan inboxes:expire
php artisan inboxes:expire --confirm --batch=10
```

## Retention cleanup

- [ ] `INBOUND_RETENTION_CLEANUP_ENABLED=true` if email pruning required
- [ ] `INBOUND_RETENTION_EMAIL_DAYS` set appropriately
- [ ] Inbound hold support enabled (`inbound_retention.inbound_hold_supported=true`)
- [ ] Smoke test:

```bash
php artisan inbound:cleanup
php artisan inbound:cleanup --confirm
```

## Renewal (optional)

- [ ] `INBOX_RENEWAL_ENABLED=true` only if product requires extension API
- [ ] `INBOX_MAX_EXTENSION_HOURS_PER_REQUEST` configured
- [ ] `RATE_LIMIT_INBOX_RENEWAL_PER_HOUR` set

## Queue / workers

- [ ] Inbound workers on `default` queue (mail delivery to inboxes)
- [ ] `attachment-scanning` workers if attachments enabled

## API smoke test

1. Create inbox with valid API key (`inboxes:write`)
2. List inboxes (`inboxes:read`)
3. Receive test inbound email (signed webhook)
4. List emails for inbox
5. Delete inbox (`DELETE`)
6. Confirm email API returns 404 for deleted inbox

## Security

- [ ] Owner isolation verified (foreign inbox → 404)
- [ ] Soft-deleted addresses cannot be re-created without policy review
- [ ] Audit logs enabled and retained

## Monitoring

- [ ] `processes:scheduler-heartbeat` monitored
- [ ] Alert on `inboxes:expire` command failures (exit code 1)
- [ ] Commercial threshold notifications configured

## Config / route cache

```bash
php artisan config:cache
php artisan route:cache
php artisan schedule:list
```

Verify `inboxes:expire` and `inbound:cleanup` appear when flags enabled.

## Rollback

1. Disable schedulers via env flags (`INBOX_EXPIRATION_SCHEDULER_ENABLED=false`)
2. `php artisan config:cache` after revert
3. Existing inboxes unaffected; no data migration required for rollback

## Related documents

- `docs/INBOX_LIFECYCLE_AUDIT.md`
- `docs/INBOX_OPERATIONS_RUNBOOK.md`
- `docs/INBOX_LIFETIME_POLICY.md`
- `docs/INBOUND_MAIL_DEPLOYMENT_CHECKLIST.md`
