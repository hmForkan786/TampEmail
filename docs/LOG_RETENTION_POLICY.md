# API Request and Audit Log Retention Policy

Status: implemented bounded cleanup for API request logs; scheduled audit deletion is explicitly disabled by default and requires legal-hold support plus configuration approval.

## Scope

This policy applies only to `api_request_logs` and `audit_logs`. They are separate retention classes and must never share one purge decision.

The application now has a legal-hold store. Archival and backup-specific log-retention policy remain separate concerns.

## Periods and configuration

| Log class | Recommended default | Minimum | Maximum | Configuration |
|---|---:|---:|---:|---|
| API request logs | 30 days | 7 days | 365 days | `API_REQUEST_LOG_RETENTION_DAYS` |
| Audit logs | 2,555 days (7 years) | 365 days | 3,650 days (10 years) | `AUDIT_LOG_RETENTION_DAYS` |

Scheduled audit deletion is controlled separately by `AUDIT_LOG_RETENTION_CLEANUP_ENABLED=false`. Missing or invalid values are treated as disabled. Enabling this setting requires an approved rollout by an active platform administrator/security owner after confirming legal-hold support and the configured retention bounds.

These are recommended defaults because no business, contractual, or jurisdiction-specific requirement is currently present in the repository. Operators must obtain compliance/legal approval before selecting a shorter period.

Values outside the configured minimum/maximum, including zero, negative, non-numeric, or missing deployment values, must fail closed during deployment/startup validation. Cleanup must not run until the values are valid. The config contract exposes the bounds in `config/retention.php`.

## Data classes

### API request logs

Operational/debugging data has the shorter period. Successful, rejected, throttled, and controller-error request records are retained uniformly within that period. IP address, API-key UUID, and owner UUID are identifiers with privacy impact; they must not be copied into a longer-lived archive by default.

### Audit logs

Security/compliance evidence has the longer period. Role/status changes, API-key revocations, and MailServer create/update events remain available to the admin-only read-only resource during retention. Audit records are immutable and are not ordinary operational request logs.

## Cleanup and archive sequence

Prompt 348 should run a controlled daily cleanup using `QUEUE_RETENTION`/the retention queue or an approved scheduler convention:

1. Validate both retention values and acquire a single-run lock.
2. Exclude rows covered by an active security/legal hold.
3. Dry-run and report candidate counts before enabling deletion.
4. Delete API request logs older than the API cutoff in bounded batches.
5. Process audit logs separately, only after hold and compliance checks.
6. Emit metrics for scanned, held, deleted, failed, and remaining rows.
7. Alert on validation failure, lock contention, deletion failure, or unexpected volume.

No archive is required by the current policy. If compliance requires archival, it must be an encrypted, access-controlled archive with its own approved retention period; it must preserve no plaintext tokens, hashes, Authorization headers, request bodies, response bodies, passwords, secrets, or credentials.

## Deletion, anonymization, and holds

The current policy recommends hard deletion in bounded batches after the retention cutoff. Soft delete is not supported by either schema and must not be simulated by mutating immutable audit records.

No identifier anonymization is required before expiry because the records are deleted. If an approved privacy process requires anonymization instead, it must be separately designed and must preserve audit integrity; API-key UUIDs and owner IDs must not be replaced in a way that breaks security evidence without approval.

An active investigation, incident, legal hold, regulatory hold, or security review overrides normal expiry. Held rows must be excluded from cleanup, have an auditable hold reference, and remain protected until the hold is explicitly released by an authorized security/compliance administrator.

Manual purge requires an active platform admin plus an approved security/compliance authorization. Operators and ordinary users cannot purge logs. Manual purge must support dry-run, bounded scope, reason, approver, actor, timestamp, and result metrics. Prompt 348 must not expose a general delete action in Filament.

## Backup and restore

Backups must follow the same classification and access controls as the source database. Backup retention does not extend the application log-retention promise unless explicitly approved. Restored data must be re-evaluated against current retention cutoffs and holds before becoming operationally visible.

The existing safe logging rules remain mandatory: no plaintext token, key hash, Authorization header, request body, response body, password, secret, or credential may be added to a backup/archive payload by application code.

## Filament visibility

Expired/deleted API request logs disappear from the API Request Log resource. Expired audit logs disappear from the Audit Log resource unless preserved by hold or an approved archive view. Neither resource may reveal archived raw payloads or provide purge/export actions under this policy.

## Cleanup command

`php artisan logs:cleanup --dry-run` reports eligible rows without deleting. `--confirm` is required for API request-log deletion; `--confirm-audit-delete` is additionally required for audit deletion. Audit deletion must select only rows with no active hold: `released_at IS NULL AND (held_until IS NULL OR held_until > now())`. Cleanup is bounded by `LOG_RETENTION_BATCH_SIZE` (default 500) and scheduled daily with `withoutOverlapping()`.

### Scheduled approval and runbook

1. Run `php artisan logs:cleanup --dry-run` and review eligible API/audit counts.
2. Verify active, indefinite, and future legal/security holds before approval.
3. Confirm retention values remain within their configured minimum and maximum bounds.
4. Take or verify the approved database backup according to the backup policy.
5. Set `AUDIT_LOG_RETENTION_CLEANUP_ENABLED=true`, clear/rebuild cached configuration, and verify `php artisan schedule:list` shows the scheduled command with `--confirm-audit-delete`.
6. Monitor deleted counts, held/skipped counts, failed batches, duration, and database alerts.
7. Disable immediately by setting the variable to `false`, then clear/rebuild cached configuration; the next scheduled run will continue API cleanup but will not delete audit logs.
8. For emergency stop, disable the setting and stop the scheduler worker; never use a hidden force-delete option.
9. After the run, repeat dry-run and compare remaining eligible counts with the deletion report.

When disabled, scheduled API cleanup remains available and audit deletion is not authorized. Manual audit cleanup still requires both `--confirm` and `--confirm-audit-delete`.

## Prompt 348 implementation contract

Implement only after approval of these defaults and bounds:

- add a validated retention settings reader;
- add a dry-run-capable, locked, bounded cleanup command/job;
- process API and audit tables independently;
- enforce legal/security holds;
- add metrics and alerts;
- add tests for invalid configuration, dry-run, holds, batch deletion, failure recovery, and restore cutoff handling;
- document the operational authorization and backup interaction.
