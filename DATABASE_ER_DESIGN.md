# DATABASE ER DESIGN

## Purpose

This document defines the database entity relationship design for the Temporary Email SaaS.

It covers entity relationships, primary key strategy, UUID versus auto-increment decisions, foreign key rules, cascade rules, indexes, soft deletes, and archive strategy.

No migrations, models, or application code are defined here.

## Database Principles

- MySQL is the durable source of truth.
- Redis is used for cache, locks, throttling, queues, and short-lived state.
- Tables must be designed for high write volume and high read volume.
- Email ingestion must be idempotent.
- Message listing must remain fast at large scale.
- Retention and archival must be first-class database concerns.
- Sensitive data must be minimized, redacted, or separated where practical.
- Public identifiers must not expose internal row counts.

## Primary Key Strategy

### Recommended Strategy

Use internal unsigned big integer primary keys for core relational tables and public UUID or ULID identifiers for external references.

### Why

- Auto-incrementing big integers are compact, fast, and efficient for MySQL indexes.
- Public UUID or ULID values prevent enumeration in URLs and APIs.
- Separating internal keys from public identifiers gives performance and security benefits.

### Rule

Most core tables should use:

- Internal primary key: `BIGINT UNSIGNED AUTO_INCREMENT`
- Public identifier: `public_id` using UUID or ULID

### UUID or ULID

Prefer ULID for public identifiers.

Reasons:

- Sortable by time.
- Easier to debug than UUID.
- Safer for public exposure than sequential IDs.
- Better index locality than random UUID v4.

Use UUID only when required by external integrations or standards.

## Core Entity Relationship Overview

```text
users
  ├── teams
  ├── inboxes
  ├── api_tokens
  ├── subscriptions
  ├── billing_customers
  ├── notifications
  └── audit_logs

teams
  ├── team_members
  ├── inboxes
  ├── api_tokens
  ├── subscriptions
  └── audit_logs

domains
  ├── inboxes
  ├── aliases
  ├── domain_health_checks
  └── inbound_routes

aliases
  └── inboxes

inboxes
  ├── messages
  ├── inbox_access_tokens
  ├── inbox_events
  └── audit_logs

messages
  ├── message_bodies
  ├── message_headers
  ├── attachments
  ├── message_recipients
  └── audit_logs

attachments
  └── attachment_scans

plans
  ├── subscriptions
  └── plan_entitlements

subscriptions
  ├── subscription_items
  ├── invoices
  └── usage_records

settings
  └── settings_audit_logs

advertisements
  ├── ad_placements
  ├── ad_impressions
  └── ad_clicks

audit_logs
  └── audit_log_metadata
```

## Main Entities

## 1. Users

### Responsibility

Stores registered user identity, account status, authentication-related ownership, and role context.

### Relationships

- One user may own many inboxes.
- One user may belong to many teams through team memberships.
- One user may own many API tokens.
- One user may have one or many subscriptions depending on billing design.
- One user may have many audit logs as actor or subject.

### Key Strategy

- Internal primary key: auto-increment big integer.
- Public identifier: ULID.

### Index Strategy

- Unique index on email.
- Index on public identifier.
- Index on account status.
- Index on created date.

### Delete Strategy

- Soft delete users.
- Do not cascade delete messages automatically.
- Use anonymization for privacy deletion workflows.

## 2. Teams

### Responsibility

Stores shared workspace ownership for future team plans.

### Relationships

- A team belongs to an owner user.
- A team has many members.
- A team may own inboxes, API tokens, subscriptions, and audit logs.

### Key Strategy

- Internal primary key: auto-increment big integer.
- Public identifier: ULID.

### Index Strategy

- Index on owner user.
- Index on public identifier.
- Index on status.

### Delete Strategy

- Soft delete teams.
- Team-owned resources must be retained or anonymized according to policy.

## 3. Team Members

### Responsibility

Represents user membership in teams and role assignment.

### Relationships

- Belongs to user.
- Belongs to team.

### Key Strategy

- Internal primary key: auto-increment big integer.

### Index Strategy

- Unique composite index on team and user.
- Index on user.
- Index on role.

### Delete Strategy

- Hard delete membership rows when a user leaves if audit logs preserve the event.
- Soft delete only if membership history must be queryable directly.

## 4. Domains

### Responsibility

Stores available inbound email domains and their operational state.

### Relationships

- One domain has many aliases.
- One domain has many inboxes.
- One domain has many health checks.
- One domain has many inbound routes.

### Key Strategy

- Internal primary key: auto-increment big integer.
- Public identifier: ULID.

### Index Strategy

- Unique index on domain name.
- Index on status.
- Index on public availability.
- Index on health status.

### Delete Strategy

- Soft delete domains.
- Domains with historical messages must not be hard deleted.

## 5. Aliases

### Responsibility

Stores reserved or generated local parts for email addresses.

### Relationships

- Belongs to domain.
- May belong to user or team.
- May have one active inbox.
- May have many historical inboxes if alias reuse is allowed after expiration.

### Key Strategy

- Internal primary key: auto-increment big integer.
- Public identifier: ULID.

### Index Strategy

- Composite unique index on domain and normalized alias for active reservations.
- Index on user.
- Index on team.
- Index on status.
- Index on alias type.

### Delete Strategy

- Soft delete reserved aliases.
- Expired random aliases may be hard deleted after retention if no audit or history requirement exists.

## 6. Inboxes

### Responsibility

Stores temporary inbox lifecycle, ownership, address mapping, expiration, and access state.

### Relationships

- Belongs to domain.
- May belong to alias.
- May belong to user.
- May belong to team.
- Has many messages.
- Has many inbox events.
- Has many access tokens.

### Key Strategy

- Internal primary key: auto-increment big integer.
- Public identifier: ULID.

### Index Strategy

- Unique active address index using normalized alias and domain.
- Index on public identifier.
- Index on domain and alias.
- Index on user and status.
- Index on team and status.
- Index on expires at.
- Index on status and expires at.
- Index on created at.

### Delete Strategy

- Soft delete inboxes.
- Expired inboxes remain until retention cleanup.
- Public access must stop when status becomes expired, blocked, or deleted.

## 7. Inbox Access Tokens

### Responsibility

Stores secure access references for anonymous inboxes or private inbox links.

### Relationships

- Belongs to inbox.

### Key Strategy

- Internal primary key: auto-increment big integer.
- Store token hash, never raw token.

### Index Strategy

- Unique index on token hash.
- Index on inbox.
- Index on expires at.

### Delete Strategy

- Hard delete expired tokens through cleanup jobs.

## 8. Inbox Events

### Responsibility

Stores lifecycle events for inboxes such as created, renewed, expired, blocked, or deleted.

### Relationships

- Belongs to inbox.
- May belong to actor user.

### Key Strategy

- Internal primary key: auto-increment big integer.

### Index Strategy

- Index on inbox.
- Index on event type.
- Index on created at.

### Delete Strategy

- Archive or prune according to audit and analytics requirements.

## 9. Messages

### Responsibility

Stores inbound message metadata and lifecycle status.

### Relationships

- Belongs to inbox.
- Belongs to domain through inbox or direct recipient domain reference.
- Has one or more recipients.
- Has one body record.
- Has many headers.
- Has many attachments.
- Has many audit logs.

### Key Strategy

- Internal primary key: auto-increment big integer.
- Public identifier: ULID.

### Index Strategy

- Index on inbox and received at.
- Index on inbox and status.
- Index on public identifier.
- Index on message ID hash.
- Index on content fingerprint.
- Index on sender hash or normalized sender where privacy policy allows.
- Index on received at.
- Index on expires at.
- Composite index on status and received at for processing dashboards.

### Delete Strategy

- Soft delete messages for user-facing deletion.
- Hard delete or archive after retention expires.
- Message body may be deleted earlier than metadata depending on policy.

## 10. Message Bodies

### Responsibility

Stores sanitized and raw body references for messages.

### Relationships

- Belongs to message.

### Key Strategy

- Internal primary key: auto-increment big integer.

### Storage Rule

- For small-scale deployment, sanitized text and HTML may be stored in MySQL.
- For large-scale deployment, raw and sanitized body content should move to object storage with database references.

### Index Strategy

- Unique index on message.
- Index on storage driver.

### Delete Strategy

- Hard delete body content during retention cleanup.
- Preserve metadata only if analytics or audit requires it.

## 11. Message Headers

### Responsibility

Stores selected normalized headers or references to raw header storage.

### Relationships

- Belongs to message.

### Key Strategy

- Internal primary key: auto-increment big integer.

### Index Strategy

- Index on message.
- Optional composite index on header name and hash value for abuse analysis.

### Delete Strategy

- Delete with message retention cleanup.
- Avoid storing unnecessary sensitive headers.

## 12. Message Recipients

### Responsibility

Stores recipient addresses extracted from inbound messages.

### Relationships

- Belongs to message.
- May reference inbox when recipient maps to a known inbox.

### Key Strategy

- Internal primary key: auto-increment big integer.

### Index Strategy

- Index on message.
- Index on normalized recipient hash.
- Index on inbox.

### Delete Strategy

- Delete with message retention cleanup.

## 13. Attachments

### Responsibility

Stores attachment metadata, storage references, safety status, and download policy.

### Relationships

- Belongs to message.
- Has many scans.

### Key Strategy

- Internal primary key: auto-increment big integer.
- Public identifier: ULID.

### Index Strategy

- Index on message.
- Index on public identifier.
- Index on status.
- Index on content hash.
- Index on storage driver.
- Index on created at.

### Delete Strategy

- Soft delete for user-facing removal.
- Hard delete file and metadata after retention.

## 14. Attachment Scans

### Responsibility

Stores malware scan results, scanner metadata, verdicts, and timestamps.

### Relationships

- Belongs to attachment.

### Key Strategy

- Internal primary key: auto-increment big integer.

### Index Strategy

- Index on attachment.
- Index on verdict.
- Index on scanned at.

### Delete Strategy

- Delete with attachment retention.
- Archive only aggregated risk metrics if needed.

## 15. API Tokens

### Responsibility

Stores API access credentials and scopes.

### Relationships

- Belongs to user or team.

### Key Strategy

- Internal primary key: auto-increment big integer.
- Store token hash only.

### Index Strategy

- Unique index on token hash.
- Index on user.
- Index on team.
- Index on status.
- Index on last used at.

### Delete Strategy

- Soft delete or revoke tokens.
- Hard delete old revoked tokens after security retention period.

## 16. Plans

### Responsibility

Stores subscription plan definitions.

### Relationships

- Has many subscriptions.
- Has many plan entitlements.

### Key Strategy

- Internal primary key: auto-increment big integer.
- Stable slug as public business identifier.

### Index Strategy

- Unique index on slug.
- Index on status.

### Delete Strategy

- Soft delete plans.
- Do not hard delete plans referenced by subscriptions.

## 17. Plan Entitlements

### Responsibility

Stores plan limits and enabled capabilities.

### Relationships

- Belongs to plan.

### Key Strategy

- Internal primary key: auto-increment big integer.

### Index Strategy

- Unique composite index on plan and entitlement key.

### Delete Strategy

- Soft delete or version entitlements if historical billing accuracy requires it.

## 18. Subscriptions

### Responsibility

Stores user or team subscription state.

### Relationships

- Belongs to plan.
- Belongs to user or team.
- Has many invoices.
- Has many usage records.

### Key Strategy

- Internal primary key: auto-increment big integer.
- Public identifier: ULID.

### Index Strategy

- Index on user.
- Index on team.
- Index on plan.
- Index on status.
- Index on renews at.
- Index on external provider ID.

### Delete Strategy

- Soft delete subscriptions.
- Preserve billing history.

## 19. Invoices

### Responsibility

Stores invoice metadata and payment state.

### Relationships

- Belongs to subscription.
- Belongs to user or team.

### Key Strategy

- Internal primary key: auto-increment big integer.
- Public identifier: ULID or provider invoice ID.

### Index Strategy

- Index on subscription.
- Index on user.
- Index on team.
- Index on status.
- Index on due date.
- Index on provider invoice ID.

### Delete Strategy

- Do not hard delete active billing records.
- Archive according to legal requirements.

## 20. Usage Records

### Responsibility

Tracks metered usage such as API calls, inbox count, message count, and storage usage.

### Relationships

- Belongs to user, team, subscription, or plan context.

### Key Strategy

- Internal primary key: auto-increment big integer.

### Index Strategy

- Composite index on owner and metric key.
- Composite index on metric key and period.
- Index on subscription.

### Delete Strategy

- Aggregate and archive old granular usage records.

## 21. Notifications

### Responsibility

Stores database notifications and delivery metadata.

### Relationships

- Belongs to notifiable user or team.

### Key Strategy

- Laravel-compatible notification identifier.

### Index Strategy

- Index on notifiable type and ID.
- Index on read at.
- Index on created at.

### Delete Strategy

- Prune old read notifications.

## 22. Settings

### Responsibility

Stores admin-managed runtime settings.

### Relationships

- May be global, per team, per domain, or per feature.

### Key Strategy

- Internal primary key: auto-increment big integer.
- Unique setting key scoped by owner.

### Index Strategy

- Unique composite index on scope type, scope ID, and key.
- Index on group.

### Delete Strategy

- Soft delete or version high-risk settings.

## 23. Audit Logs

### Responsibility

Stores security, admin, user, API, and system audit events.

### Relationships

- May belong to actor user.
- May reference subject entity through polymorphic fields.
- May reference team, inbox, message, domain, or API token.

### Key Strategy

- Internal primary key: auto-increment big integer.
- Optional public identifier for exports.

### Index Strategy

- Index on actor.
- Index on subject type and subject ID.
- Index on event type.
- Index on created at.
- Index on IP hash.
- Composite index on event type and created at.

### Delete Strategy

- Append-only during active retention.
- Archive after retention period.
- Redact sensitive metadata before long-term storage.

## 24. Analytics Events

### Responsibility

Stores product and operational events for aggregation.

### Relationships

- May reference user, team, domain, inbox, message, or API token.

### Key Strategy

- Internal primary key: auto-increment big integer.

### Index Strategy

- Index on event type.
- Index on occurred at.
- Composite index on event type and occurred at.
- Composite index on owner and occurred at.

### Delete Strategy

- Aggregate into rollup tables.
- Delete or archive raw events after reporting window.

## 25. Advertisements

### Responsibility

Stores ad campaign definitions.

### Relationships

- Has many placements.
- Has many impressions and clicks through placements.

### Key Strategy

- Internal primary key: auto-increment big integer.
- Public identifier: ULID.

### Index Strategy

- Index on status.
- Index on starts at and ends at.

### Delete Strategy

- Soft delete campaigns.
- Preserve impression and click aggregates.

## 26. Ad Placements

### Responsibility

Stores page or surface-specific ad placement rules.

### Relationships

- Belongs to advertisement.

### Key Strategy

- Internal primary key: auto-increment big integer.

### Index Strategy

- Index on placement key.
- Index on status.

### Delete Strategy

- Soft delete placements.

## 27. Ad Impressions and Clicks

### Responsibility

Stores ad interaction analytics with privacy-safe metadata.

### Relationships

- Belongs to advertisement.
- Belongs to placement.
- May reference user when authenticated.

### Key Strategy

- Internal primary key: auto-increment big integer.

### Index Strategy

- Index on advertisement.
- Index on placement.
- Index on occurred at.
- Composite index on advertisement and occurred at.

### Delete Strategy

- Aggregate and prune raw records.

## Foreign Key Rules

### General Rules

- Use foreign keys for core ownership and lifecycle relationships.
- Avoid foreign keys where extremely high-volume ingestion could be blocked by lock contention, but document the tradeoff.
- Use nullable foreign keys for optional ownership, such as anonymous inboxes.
- Use explicit indexes for all foreign keys.
- Do not rely only on database constraints for business rules.

### Required Foreign Key Areas

- Team members to users and teams.
- Inboxes to domains.
- Inboxes to users or teams when owned.
- Messages to inboxes.
- Attachments to messages.
- Attachment scans to attachments.
- Subscriptions to plans.
- Plan entitlements to plans.
- Invoices to subscriptions.

### Optional Foreign Key Areas

- Audit logs to subject entities may use polymorphic references without strict foreign keys.
- Analytics events may avoid strict foreign keys for write throughput.
- Inbound raw payload records may avoid strict foreign keys until normalized.

## Cascade Rules

### Safe Cascades

Use cascade delete only for dependent records that have no value without the parent:

- Attachment scans when attachment is deleted.
- Message headers when message is permanently deleted.
- Message recipients when message is permanently deleted.
- Inbox access tokens when inbox is permanently deleted.
- Team member rows when team is permanently deleted.

### Restricted Cascades

Do not cascade automatically from these parents:

- Users.
- Teams.
- Domains.
- Inboxes.
- Messages before retention rules are applied.
- Plans.
- Subscriptions.
- Audit logs.

### Preferred Rule

Use application-controlled deletion workflows for sensitive or high-value data. Cascades should support cleanup, not define business retention policy.

## Index Strategy

### General Index Rules

- Every foreign key must have an index.
- Every public identifier must have an index.
- Every status field used in lists must have an index.
- Every expiration or cleanup field must have an index.
- Every high-volume list must use a composite index matching its filter and sort order.
- Avoid indexing large text columns.
- Hash sensitive searchable values where exact lookup is needed.

### Critical Indexes

- Inboxes by normalized address.
- Inboxes by status and expiration.
- Messages by inbox and received date.
- Messages by status and received date.
- Messages by message ID hash.
- Messages by expiration date.
- Attachments by message.
- API tokens by token hash.
- Audit logs by actor and created date.
- Analytics events by event type and occurred date.
- Usage records by owner, metric, and period.

### High-Volume Query Pattern

Message listing should prefer:

```text
inbox_id + received_at DESC
```

Retention cleanup should prefer:

```text
status + expires_at
```

API usage lookup should prefer:

```text
owner + metric_key + period
```

## Soft Delete Strategy

### Use Soft Deletes For

- Users.
- Teams.
- Domains.
- Reserved aliases.
- Inboxes.
- Messages.
- Attachments.
- Plans.
- Subscriptions.
- Advertisements.
- Ad placements.

### Avoid Soft Deletes For

- High-volume raw analytics events.
- Temporary access tokens.
- Message headers after message cleanup.
- Message recipients after message cleanup.
- Attachment scan rows after attachment cleanup.
- Short-lived ingestion payload records.

### Rule

Soft delete is for business recovery, auditability, and user-facing deletion states. It is not a replacement for retention cleanup.

## Archive Strategy

### Archive Goals

- Keep production tables fast.
- Preserve required audit and billing history.
- Minimize stored sensitive email content.
- Support compliance and operational investigation.
- Control storage costs.

### Archive Candidates

- Old messages.
- Old message metadata.
- Old audit logs.
- Old analytics events.
- Old usage records.
- Old invoices.
- Old domain health checks.
- Old inbox events.

### Archive Destinations

- MySQL archive tables for queryable historical metadata.
- Object storage for compressed exports or large bodies.
- Data warehouse for analytics rollups.
- Cold storage for compliance records.

### Archive Rules

- Message bodies should usually be deleted instead of archived unless a paid plan or compliance requirement says otherwise.
- Audit logs should be retained longer than message bodies.
- Billing records should follow legal and tax retention requirements.
- Analytics events should be aggregated before raw events are pruned.
- Archive jobs must run in batches.
- Archive jobs must be resumable.
- Archive jobs must emit metrics.
- Archive jobs must never block email ingestion.

## Retention Recommendations

### Anonymous Inboxes

- Short inbox lifetime.
- Short message lifetime.
- Aggressive cleanup.
- Minimal metadata retention.

### Registered Free Users

- Moderate inbox lifetime.
- Moderate message lifetime.
- Limited attachment retention.

### Paid Users

- Longer retention based on plan.
- Optional reserved aliases.
- Optional attachment retention.

### Audit and Security

- Longer retention than message content.
- Redacted where possible.
- Protected from ordinary user deletion workflows.

## ER Design Review Checklist

- Does each table have a clear owner module?
- Does each table have a primary key strategy?
- Are public identifiers non-enumerable?
- Are high-volume queries indexed?
- Are cleanup queries indexed?
- Are retention rules defined?
- Are cascade rules safe?
- Are sensitive values minimized or hashed?
- Are archival candidates identified?
- Can ingestion continue if analytics or audit pipelines are delayed?
- Can old data be deleted without breaking current product behavior?

