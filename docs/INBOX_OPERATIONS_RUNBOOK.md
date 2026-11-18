# Inbox Operations Runbook

Operational guide for inbox lifecycle, expiry, retention, and holds. See `INBOX_LIFECYCLE_AUDIT.md` for audit results.

## Quick reference

| Command | Purpose |
| --- | --- |
| `php artisan inboxes:expire` | Dry-run expired inbox report |
| `php artisan inboxes:expire --confirm` | Deactivate + soft-delete expired inboxes |
| `php artisan inbound:cleanup` | Dry-run email retention |
| `php artisan inbound:cleanup --confirm` | Delete emails past retention (hold-aware) |
| `php artisan schedule:list` | Verify lifecycle jobs scheduled |

## Lifecycle overview

```text
POST /api/v1/inboxes          → create (entitlement + mail server)
DELETE /api/v1/inboxes/{id}   → deactivate + soft-delete
PATCH .../expiration          → extend expires_at (if renewal enabled)
inboxes:expire --confirm      → batch expire past expires_at
inbound:cleanup --confirm     → delete old emails
```

## Inbox creation failures

### Symptom: 403 commercial denial

- Check `inbox.create`, `inbox.max_active`, `inbox.custom_alias`, `inbox.retention_hours` entitlements
- Review `audit_logs` for `commercial.limit_reached`
- User may need to delete inactive inboxes or upgrade plan

### Symptom: 409 duplicate_inbox_address

- Address exists (including soft-deleted row)
- Choose different `local_part` or wait for hard-delete policy

### Symptom: 503 mail_server_unavailable

- No eligible mail server in entitled pool or public pool full
- Check `mail-servers:pool-status` / HA refresh
- Verify `mail_server_pools` entitlement JSON and server capacity

## Expiry operations

### Enable scheduled expiration

```env
INBOX_EXPIRATION_SCHEDULER_ENABLED=true
```

Scheduler runs `inboxes:expire --confirm` daily with `withoutOverlapping`.

### Manual expiration

```bash
php artisan inboxes:expire              # report only
php artisan inboxes:expire --confirm    # mutate
php artisan inboxes:expire --confirm --batch=50
```

**Behavior:** Sets `is_active=false`, soft-deletes inbox, audits `inbox.expired`. Child emails are **not** deleted.

### Inbound still delivering to expired address

- Resolver should return `expired` when `expires_at` is past
- If mail still accepted, verify `expires_at` timezone and resolver cache
- Run expire job to soft-delete if past expiry

## Retention / email cleanup

### Enable scheduled cleanup

```env
INBOUND_RETENTION_CLEANUP_ENABLED=true
```

Requires `inbound_retention.inbound_hold_supported=true`.

```bash
php artisan inbound:cleanup              # dry-run eligible count
php artisan inbound:cleanup --confirm    # delete
```

**Skips:** emails with active `InboundHold`, attachments in `pending`/`scanning`.

## Holds

### Create hold (platform admin)

Via Filament `InboundHolds` or `CreateInboundHoldAction` — targets email, attachment, or inbox.

### Release hold

`ReleaseInboundHoldAction` — admin only; audited.

### Cleanup blocked by holds

`inbound:cleanup` increments `held` count for held rows. Release holds before retrying cleanup.

## Deletion

User `DELETE /api/v1/inboxes/{id}`:

- Requires active inbox (`is_active=true`)
- Immediate soft-delete; no grace period
- Emails remain until separate retention cleanup

## Renewal

Disabled by default. Enable:

```env
INBOX_RENEWAL_ENABLED=true
```

`PATCH /api/v1/inboxes/{id}/expiration` with `extension_hours`. Cannot renew expired, inactive, or soft-deleted inboxes.

## Commercial / subscription changes

- Grace period → Free entitlements for **new** operations
- Existing inboxes not auto-deleted on downgrade
- Monitor inbox count vs `inbox.max_active` after plan change
- No automatic excess-inbox pruning job

## Visibility inconsistencies (expected)

| API | Expired inbox visible? |
| --- | --- |
| `GET /api/v1/inboxes` | Yes (use `?expired=false` to filter) |
| `GET /api/v1/inboxes/{id}/emails` | No (404) |

## Incident evidence

Safe to collect:

- Inbox ID, `full_address` hash, `expires_at`, `is_active`, `deleted_at`
- `audit_logs` for `inbox.created`, `inbox.deactivated`, `inbox.expired`
- Expire/cleanup command JSON output
- Entitlement feature values for user

Do not export API key secrets or full mailbox contents unless authorized.

## Escalation

| Severity | Condition | Action |
| --- | --- | --- |
| P1 | Mass create failures (503/503 pool) | Fix mail server pool / capacity |
| P2 | Expired inboxes not cleaning up | Enable scheduler, run `inboxes:expire --confirm` |
| P3 | Retention not running | Enable `INBOUND_RETENTION_CLEANUP_ENABLED`, check holds |
