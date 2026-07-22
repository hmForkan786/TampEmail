# Inbound Email and Attachment Retention Policy

This policy is separate from LOG_RETENTION_POLICY.md, which governs API request and security audit logs. It defines policy only; no cleanup command or job is enabled by this document.

## Default periods

| Data | Default | Bounds | Policy |
| --- | ---: | ---: | --- |
| Email metadata | 30 days | 1-365 | Remove only after the email cutoff |
| Email body | 30 days | 1-365 | Must not outlive its email |
| Clean attachment | 30 days | 1-365 | Private quarantine object and row together |
| Infected attachment | 90 days | 1-730 | Preserve for security investigation |
| Pending/scanning attachment | 7 days | 1-30 | Fail closed; never purge while processing |
| Failed attachment | 30 days | 1-365 | Preserve failure evidence before purge |
| Email events/processing logs | 30 days | 1-365 | Operational evidence |
| Inbound failure/DLQ records | 90 days | 1-730 | Replay and incident evidence |

An expired inbox does not immediately delete messages. Message retention is evaluated independently, while new delivery must already be blocked by inbox expiry or inactive state. User-owned and anonymous messages follow the same technical defaults; legal or product exceptions must be explicit.

## Safety and lifecycle

Cleanup must be bounded, batch-based, dry-run first, and explicitly authorized. Invalid or zero retention values disable that category; they never mean delete everything. Pending or scanning attachments are fail-closed and excluded. Soft deletion is preferred for email rows where supported; private storage objects may be hard-deleted only after the database record is safely handled.

An attachment object without a database record is an orphan. Orphan cleanup must use a quarantine-prefix inventory, age threshold, dry-run, and a second confirmation; newer objects or objects related to an active hold are preserved. Database deletion and object deletion failures are retried and alerted, never silently ignored.

Legal/security holds override every inbound category. Held records and linked body, attachment, event and failure evidence are excluded until release. Holds use the existing audit-log hold foundation; this policy does not alter audit retention.

Backups may retain data beyond the application cutoff under the backup schedule and legal-hold rules. Restores must not bypass redaction or hold checks. Manual purge is restricted to an active platform admin and must emit an audit event with counts, category, cutoff and outcome, never content.

Metrics include eligible, deleted, skipped-held, orphan candidates, object-delete failures, database-delete failures, retries and duration. Alert on failures, invalid configuration, and pending or scanning records beyond their review window.

Filament/API visibility ends when the underlying row is removed or soft-deleted; no raw message or attachment content is exposed by this policy.

Configuration is in config/inbound_retention.php; API/audit log retention remains in config/retention.php.
