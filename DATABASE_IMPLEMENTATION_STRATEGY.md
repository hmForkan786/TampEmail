# DATABASE IMPLEMENTATION STRATEGY

## Purpose

This document defines the complete database implementation strategy for the Temporary Email SaaS platform described in `PROJECT_SPECIFICATION.md`, `PROJECT_ARCHITECTURE.md`, `MODULE_BREAKDOWN.md`, and `DATABASE_ER_DESIGN.md`.

It governs how schema is designed, migrated, indexed, retained, archived, and operated at scale. It is a strategy document only.

**This document does not define migrations, SQL, Eloquent models, or application logic.**

## Scope and Authority

- MySQL 8.x is the durable source of truth for relational data.
- Redis handles cache, locks, throttling, queues, and short-lived state — not durable business records.
- Laravel 12 conventions apply to timestamps, soft deletes, notifications, and queue infrastructure.
- All implementation must remain compatible with horizontal scaling, queue-based ingestion, and Filament v4 admin workloads.

When this document and `DATABASE_ER_DESIGN.md` disagree on entity relationships, `DATABASE_ER_DESIGN.md` defines entities; this document defines **how** those entities are implemented in the database layer.

---

## 1. Primary Key Strategy

### Decision: Hybrid Internal BIGINT + Public ULID

| Layer | Type | Usage |
|-------|------|-------|
| Internal primary key | `BIGINT UNSIGNED AUTO_INCREMENT` | All joins, foreign keys, Eloquent relationships |
| Public identifier | `CHAR(26)` ULID in `public_id` column | URLs, API responses, webhooks, exports |

### UUID vs BIGINT Justification

**Why not UUID as primary key everywhere**

- Random UUID v4 values cause index fragmentation and poor B-tree locality in InnoDB clustered indexes.
- UUID primary keys increase row and index size (16 bytes vs 8 bytes for BIGINT), reducing buffer pool efficiency at tens of millions of rows.
- Join performance degrades when both sides of a relationship use wide, random keys.
- Auto-increment BIGINT provides predictable insert performance for high-write tables such as `messages`, `analytics_events`, and `audit_logs`.

**Why not BIGINT as the only identifier**

- Sequential IDs enable enumeration attacks on public inbox and message URLs.
- Exposing internal IDs leaks approximate row counts and growth rate.
- External integrations and API consumers require stable, non-guessable identifiers that survive internal key reassignment during archival.

**Why ULID over UUID for public identifiers**

- ULIDs are lexicographically sortable by creation time, improving index locality compared to UUID v4.
- ULIDs are easier to debug in logs and support correlation without exposing sequential counts.
- Laravel supports ULID via `Illuminate\Database\Eloquent\Concerns\HasUlids` or explicit `public_id` columns with application-generated values.
- Reserve UUID only when a third-party integration explicitly requires RFC 4122 UUID format.

### Public Identifier Rules

1. Every externally addressable entity must expose a `public_id` column: `users`, `teams`, `domains`, `aliases`, `inboxes`, `messages`, `attachments`, `subscriptions`, `invoices`, and `advertisements`.
2. `public_id` must be unique, indexed, and immutable after creation.
3. Public APIs, web routes, and JSON responses must use `public_id` — never internal `id`.
4. Admin Filament resources may display `public_id` alongside internal `id` for support workflows; internal `id` must never appear in public or API surfaces.
5. Composite public references (e.g., inbox address) are resolved through indexed lookup columns, not through exposing internal keys.

### Exceptions

| Entity | Public identifier approach | Reason |
|--------|-------------------------|--------|
| `plans` | Stable `slug` instead of ULID | Business-stable identifier for entitlements and config |
| `settings` | Scoped `key` composite | Configuration lookup, not user-facing resource |
| High-volume child rows | No public identifier | `message_headers`, `message_recipients`, `attachment_scans` are never directly exposed |
| Laravel infrastructure | Framework defaults | `jobs`, `failed_jobs`, `notifications` follow Laravel conventions |

### Laravel Compatibility

- Eloquent `$primaryKey` remains `id` (BIGINT).
- Route model binding resolves by `public_id` where resources are public-facing.
- Factory and seeder definitions must populate both `id` (auto) and `public_id` (generated).

---

## 2. Table Naming Rules

### Convention

- Use **plural**, **lowercase**, **snake_case** table names.
- Names must describe the business entity, not the technical implementation.
- Avoid prefixes unless required for namespace clarity (e.g., no `tbl_` prefix).

### Standard Examples

```text
users
teams
team_members
domains
aliases
inboxes
inbox_access_tokens
inbox_events
messages
message_bodies
message_headers
message_recipients
attachments
attachment_scans
api_tokens
plans
plan_entitlements
subscriptions
subscription_items
invoices
usage_records
settings
settings_audit_logs
audit_logs
audit_log_metadata
analytics_events
analytics_rollups
advertisements
ad_placements
ad_impressions
ad_clicks
```

### Junction and Detail Tables

- Junction tables: `{entity_a}_{entity_b}` in alphabetical or logical dependency order — `team_members`.
- Detail or extension tables: `{parent_singular}_{detail}` — `message_bodies`, `attachment_scans`.
- Event or log tables: `{entity}_events` or `{domain}_logs` — `inbox_events`, `audit_logs`.

### Laravel Infrastructure Tables

Use Laravel defaults without renaming:

```text
migrations
password_reset_tokens
sessions
cache
cache_locks
jobs
job_batches
failed_jobs
notifications
personal_access_tokens   (only if Sanctum default is retained; prefer dedicated api_tokens table per ER design)
```

### Forbidden Table Names

- Singular table names (`user`, `message`).
- Abbreviations (`msg`, `att`, `dom`).
- Module-prefixed tables that mirror code folders (`inbox_module_inboxes`).
- Reserved or ambiguous names (`data`, `records`, `items` without context).

---

## 3. Column Naming Rules

### General Convention

- Use **snake_case** for all column names.
- Prefer explicit business language over abbreviations.
- Boolean columns use `is_`, `has_`, or `can_` prefixes per `CODING_STANDARDS.md`.
- Timestamp columns use `_at` suffix (`created_at`, `expires_at`, `received_at`).
- Date-only columns use `_date` suffix when time is not stored (`billing_period_date`).

### Standard Column Categories

| Category | Pattern | Examples |
|----------|---------|----------|
| Primary key | `id` | `id BIGINT UNSIGNED` |
| Public identifier | `public_id` | `public_id CHAR(26)` |
| Foreign key | `{entity}_id` | `inbox_id`, `domain_id`, `user_id` |
| Polymorphic | `{name}_type`, `{name}_id` | `subject_type`, `subject_id` |
| Status | `status` | Values enforced by PHP enum + string column |
| Hash / fingerprint | `{field}_hash` | `message_id_hash`, `sender_hash`, `token_hash` |
| Normalized lookup | `normalized_{field}` | `normalized_alias`, `normalized_sender` |
| Storage reference | `{purpose}_path` or `storage_*` | `storage_path`, `storage_driver`, `storage_bucket` |
| Provider reference | `provider_{field}` | `provider_invoice_id`, `provider_customer_id` |
| Count / size | `{noun}_count`, `{noun}_bytes` | `attachment_count`, `size_bytes` |
| JSON payload | `{purpose}_payload` or `{purpose}_metadata` | `audit_payload`, `routing_metadata` |

### Sensitive Data Columns

- Store **hashes**, not raw secrets: `token_hash`, `ip_hash`, never `token`, never raw IP in high-volume tables.
- Never store raw API tokens, inbox access tokens, or password values in plain text.
- Email addresses in abuse or analytics contexts should use normalized hash columns when exact replay is not required.

### Column Naming Forbidden Practices

- camelCase or PascalCase column names.
- Generic names (`value`, `data`, `info`) without domain prefix.
- Negative boolean names (`is_not_active`).
- Storing serialized PHP arrays in text columns when JSON is appropriate.

---

## 4. Foreign Key Rules

### When Foreign Keys Are Required

Use explicit InnoDB foreign key constraints for **core ownership and lifecycle relationships** where referential integrity outweighs write latency:

| Child table | Parent | Nullable | Notes |
|-------------|--------|----------|-------|
| `team_members` | `users`, `teams` | No | Membership integrity |
| `inboxes` | `domains` | No | Every inbox belongs to a domain |
| `inboxes` | `users`, `teams`, `aliases` | Yes | Anonymous or unassigned inboxes |
| `messages` | `inboxes` | No | Message ownership |
| `message_bodies` | `messages` | No | One body per message |
| `message_headers` | `messages` | No | Header rows belong to message |
| `message_recipients` | `messages` | No | Recipient rows belong to message |
| `attachments` | `messages` | No | Attachment ownership |
| `attachment_scans` | `attachments` | No | Scan belongs to attachment |
| `inbox_access_tokens` | `inboxes` | No | Token scoped to inbox |
| `inbox_events` | `inboxes` | No | Event belongs to inbox |
| `aliases` | `domains` | No | Alias belongs to domain |
| `plan_entitlements` | `plans` | No | Entitlement belongs to plan |
| `subscriptions` | `plans` | No | Subscription belongs to plan |
| `subscription_items` | `subscriptions` | No | Line item belongs to subscription |
| `invoices` | `subscriptions` | Yes | Invoice may exist outside subscription in edge cases |
| `api_tokens` | `users` or `teams` | Yes | One owner required at application level |

### When Foreign Keys Are Optional or Omitted

Document the tradeoff when omitting constraints:

| Table | Reference | Reason to omit FK |
|-------|-----------|-------------------|
| `audit_logs` | Polymorphic subjects | Subjects may be archived or hard-deleted; audit must survive |
| `analytics_events` | Users, inboxes, messages | Write throughput; eventual consistency acceptable |
| Ingestion staging tables | Pre-normalized payloads | High-volume burst writes; validated in application before promotion |
| `usage_records` | Multiple optional owners | Aggregation pipeline may lag owner deletion |
| Archive tables | Production parent IDs | Parent may be purged from hot storage |

When FKs are omitted:

1. Retain indexed `{entity}_id` columns.
2. Enforce integrity in Actions before insert.
3. Document the exception in the migration header comment.
4. Run periodic reconciliation jobs for orphan detection in non-critical paths.

### Foreign Key Implementation Rules

1. Every foreign key column must have its own index (InnoDB indexes FK columns automatically, but composite queries may need additional indexes).
2. Use `UNSIGNED BIGINT` for all FK columns matching parent `id` type.
3. Name constraints explicitly: `fk_{child_table}_{parent_table}_{column}` for operational clarity.
4. Nullable FKs must use `NULL` to represent absence — never `0` as sentinel.
5. Polymorphic references must include both `{name}_type` and `{name}_id` with a composite index; FK constraints are not used on polymorphic pairs.

---

## 5. Cascade Strategy

### Principle

**Application-controlled deletion defines business policy. Database cascades support cleanup of dependent rows that have no independent meaning.**

Cascades must never replace retention workflows, anonymization, or legal hold requirements.

### ON DELETE CASCADE — Permitted

Use cascade delete only when child rows are purely dependent artifacts:

| Parent deleted (hard) | Child cascaded |
|----------------------|----------------|
| `attachments` | `attachment_scans` |
| `messages` (permanent purge) | `message_bodies`, `message_headers`, `message_recipients` |
| `inboxes` (permanent purge) | `inbox_access_tokens`, `inbox_events` |
| `teams` (permanent purge after policy) | `team_members` |
| `advertisements` (hard purge) | `ad_placements` (only when campaign fully removed) |

### ON DELETE RESTRICT — Required

Prevent accidental deletion of high-value parents:

| Parent | Protected by RESTRICT |
|--------|----------------------|
| `users` | Prevents orphaning owned resources without workflow |
| `domains` | Historical messages reference domains |
| `plans` | Active subscriptions reference plans |
| `subscriptions` | Invoices reference subscriptions |
| `inboxes` | Default RESTRICT; purge only through retention Actions |
| `messages` | Default RESTRICT; soft delete first, hard purge via batch job |

### ON DELETE SET NULL — Selective Use

Use when child record should survive but lose optional association:

| Column | When |
|--------|------|
| `inboxes.user_id` | User anonymized; inbox may remain until expiration |
| `inboxes.team_id` | Team dissolved; inbox transferred or expired |
| `messages` optional actor references | Actor deleted; message metadata preserved |

### Soft Delete Interaction

- Soft-deleting a parent must **not** trigger database cascade (soft delete sets `deleted_at`, not DELETE).
- Child visibility must be enforced by queries (`whereNull('deleted_at')`) and application policies.
- Hard purge jobs delete children in explicit order within transactions or batched chunks.

---

## 6. Soft Delete Policy

### Use Soft Deletes (`deleted_at TIMESTAMP NULL`)

Soft delete supports user-facing deletion, admin recovery, audit continuity, and billing dispute investigation.

| Table | Soft delete | Reason |
|-------|-------------|--------|
| `users` | Yes | Account recovery, GDPR workflow staging |
| `teams` | Yes | Workspace recovery |
| `domains` | Yes | Disable without losing history |
| `aliases` | Yes | Reserved alias release workflow |
| `inboxes` | Yes | User delete vs expiration distinction |
| `messages` | Yes | User-facing delete before retention purge |
| `attachments` | Yes | User-facing remove before file purge |
| `plans` | Yes | Plan retirement without breaking subscription history |
| `subscriptions` | Yes | Cancelled state vs record preservation |
| `advertisements` | Yes | Campaign disable |
| `ad_placements` | Yes | Placement disable |
| `api_tokens` | Revoke via `status` + optional soft delete | Prefer explicit `revoked_at` over soft delete alone |

### Do Not Use Soft Deletes

| Table | Strategy | Reason |
|-------|----------|--------|
| `inbox_access_tokens` | Hard delete on expiry | Short-lived credentials |
| `message_headers` | Hard delete with message purge | No independent lifecycle |
| `message_recipients` | Hard delete with message purge | High volume, no recovery value |
| `attachment_scans` | Hard delete with attachment | Operational artifact |
| `analytics_events` | Partition prune / archive | Volume makes soft delete impractical |
| `usage_records` (granular) | Aggregate then hard delete | Rollups preserve history |
| `jobs`, `failed_jobs` | Laravel defaults | Framework-managed lifecycle |
| Ingestion staging rows | Hard delete after promotion | Temporary by design |

### Soft Delete Rules

1. All soft-deleted tables must index `deleted_at` when combined with status or expiration filters.
2. Unique constraints for active records must account for soft deletes (partial unique indexes via application enforcement or composite uniqueness including `deleted_at` strategy — document chosen approach per table).
3. Soft delete is **not** retention compliance — scheduled hard purge must follow per plan/policy.
4. Public and API queries must exclude soft-deleted records by default.
5. Admin Filament resources may include trashed records with explicit filters.

---

## 7. Retention Policy

Retention is plan-aware, entity-specific, and enforced by scheduled queue jobs — not by soft delete alone.

### Retention Dimensions

| Dimension | Controlled by | Storage location |
|-----------|---------------|------------------|
| Inbox lifetime | Plan entitlement + anonymous defaults | `inboxes.expires_at` |
| Message lifetime | Plan + inbox state | `messages.expires_at` |
| Attachment lifetime | Plan + message state | `attachments.expires_at` or inherited from message |
| Token lifetime | Security policy | `inbox_access_tokens.expires_at`, `api_tokens` expiry fields |
| Audit retention | Compliance config | `config/audit.php` + archive jobs |
| Analytics raw events | Reporting window | `analytics_events.occurred_at` |
| Billing records | Legal/tax requirements | Separate long retention; no automatic purge without legal review |

### Default Retention Tiers (Configurable via `config/retention.php`)

| Tier | Inbox lifetime | Message lifetime | Attachment access | Metadata after body purge |
|------|----------------|------------------|-------------------|---------------------------|
| Anonymous | Short (hours) | Short (hours) | Disabled or minimal | Minimal |
| Registered free | Moderate (days) | Moderate (days) | Limited size/count | Moderate |
| Paid | Plan-defined (weeks+) | Plan-defined | Plan-defined | Per plan |
| Quarantined / abuse | Policy-defined | Short | Blocked | Abuse investigation window |
| Audit / security | N/A | N/A | N/A | Longer than message bodies |

### Retention Enforcement Rules

1. Set `expires_at` at creation time based on effective entitlement — do not compute dynamically on every read.
2. Expiration changes (renewal, plan upgrade) update `expires_at` through explicit Actions with audit trail.
3. Cleanup jobs query by `(status, expires_at)` or `(expires_at)` indexed ranges — never full table scans.
4. Batch size limits per job run (configurable) prevent long locks and worker starvation.
5. Cleanup jobs emit metrics: rows processed, bytes freed, duration, failures.
6. Message body purge may occur before metadata purge — track `body_purged_at` separately if needed.
7. User-initiated deletion triggers soft delete immediately; hard purge follows standard retention schedule unless GDPR erasure accelerates.

### GDPR / Privacy Erasure

- User erasure requests run a dedicated workflow: anonymize PII, revoke tokens, soft delete or hard purge per legal guidance.
- Erasure must not break billing records required for tax compliance — anonymize actor/subject links instead of deleting invoices.

---

## 8. Archiving Strategy

### Goals

1. Keep hot tables (`messages`, `inboxes`, `audit_logs`, `analytics_events`) fast for production traffic.
2. Preserve required audit, billing, and aggregated analytics history.
3. Minimize stored sensitive email content in any tier.
4. Support compliance investigation without blocking ingestion.

### Archive Tiers

| Tier | Storage | Contents | Query pattern |
|------|---------|----------|---------------|
| Hot | Primary MySQL | Active inboxes, recent messages, current subscriptions | Real-time product |
| Warm archive | MySQL archive tables (`*_archive`) or separate schema | Expired message metadata, old inbox events, aged audit logs | Admin investigation, support |
| Cold archive | Object storage (compressed JSON/Parquet exports) | Bulk historical exports, message body backups if ever retained | Rare retrieval |
| Analytics warehouse | Future OLAP / rollup tables | Aggregated metrics | Dashboards, reporting |

### Archive Candidates and Timing

| Source | Archive trigger | Destination | Sensitive content |
|--------|-----------------|-------------|-------------------|
| `messages` (metadata) | After hard retention | `messages_archive` or cold storage | Redact body references |
| `message_bodies` | At retention expiry | Delete default; object storage only if policy requires | Purge by default |
| `audit_logs` | After active retention window | `audit_logs_archive` | Redact payloads |
| `analytics_events` | After rollup job | Delete raw; keep `analytics_rollups` | Never store bodies |
| `usage_records` | After billing period close | Rollup tables | Aggregated only |
| `inbox_events` | After inbox purge | Archive or delete | Low sensitivity |
| `domain_health_checks` | After 90 days default | Aggregate then prune | Operational |
| `invoices` | Never auto-delete | Stay in primary or billing archive | Legal hold |

### Archive Job Requirements

1. Batch-oriented, resumable, idempotent.
2. Run on `retention` or `exports` queues — never `mail-ingestion`.
3. Copy then verify then delete from hot storage (or mark `archived_at` before delete).
4. Emit observability metrics and correlation IDs.
5. Must tolerate downstream warehouse delays without blocking product workflows.

---

## 9. Partitioning Strategy (Future)

Partitioning is a **future scale lever**, not a day-one requirement. Design schemas now so partitioning can be applied without redesign.

### Partition Candidates (Priority Order)

| Table | Partition key | Method | Trigger volume |
|-------|---------------|--------|----------------|
| `messages` | `received_at` (monthly RANGE) | RANGE | > 50M rows or cleanup slowdown |
| `analytics_events` | `occurred_at` (monthly RANGE) | RANGE | > 100M rows |
| `audit_logs` | `created_at` (monthly RANGE) | RANGE | > 50M rows |
| `usage_records` | `period_start` (monthly RANGE) | RANGE | High granular metering |
| `ad_impressions`, `ad_clicks` | `occurred_at` | RANGE | High ad traffic |

### Partition Design Rules

1. Partition key must align with retention and archive queries (`WHERE received_at BETWEEN ...`).
2. Primary key must include partition key if MySQL requires it for partitioned tables — plan composite PK strategy before migration.
3. Foreign keys on partitioned tables are restricted in MySQL — prefer logical references (`inbox_id` indexed, no FK) on partitioned `messages` if FK to non-partitioned `inboxes` becomes problematic.
4. Use partition pruning in all cleanup and listing queries.
5. Automate partition creation (month ahead) and drop (after archive confirmation) via scheduled commands.

### Pre-Partitioning Preparation (Implement Now)

- Always populate temporal columns at insert (`received_at`, `occurred_at`, `created_at`).
- Index leading with partition key: `(inbox_id, received_at)` supports both inbox listing and future monthly partitions.
- Avoid updates that move rows across partition boundaries.

### Sharding (Future, Beyond Partitioning)

- Shard key candidate: `domain_id` or `tenant_id` (team) for multi-region expansion.
- Not required until single-instance MySQL limits are reached; document in runbooks before implementation.

---

## 10. Index Strategy

### Index Design Principles

1. **Every foreign key column is indexed.**
2. **Every public identifier is uniquely indexed.**
3. **Every filter column used in Filament admin lists is indexed.**
4. **Every cleanup query predicate is indexed.**
5. **Composite indexes match filter order + sort order** (leftmost prefix rule).
6. **Avoid redundant indexes** — review overlapping composites during migration review.
7. **Do not index large TEXT/BLOB columns** — use hash columns for exact lookup.

### Critical Single-Column Indexes

| Table | Column(s) | Purpose |
|-------|-----------|---------|
| `users` | `email` (unique), `public_id` (unique), `status` | Auth, lookup |
| `domains` | `name` (unique), `status`, `health_status` | Routing, admin |
| `inboxes` | `public_id` (unique), `expires_at`, `status` | Access, cleanup |
| `messages` | `public_id` (unique), `message_id_hash`, `content_fingerprint`, `expires_at` | API, idempotency, cleanup |
| `api_tokens` | `token_hash` (unique), `status`, `last_used_at` | Auth, admin |
| `audit_logs` | `event_type`, `created_at` | Investigation |
| `subscriptions` | `status`, `renews_at`, `provider_subscription_id` | Billing |

### Critical Composite Indexes

| Table | Index columns | Query served |
|-------|---------------|--------------|
| `inboxes` | `(domain_id, normalized_alias)` | Active address resolution |
| `inboxes` | `(user_id, status)` | User dashboard |
| `inboxes` | `(team_id, status)` | Team dashboard |
| `inboxes` | `(status, expires_at)` | Expiration cleanup |
| `messages` | `(inbox_id, received_at DESC)` | Inbox message listing |
| `messages` | `(inbox_id, status)` | Filtered inbox views |
| `messages` | `(status, received_at)` | Processing dashboards |
| `messages` | `(status, expires_at)` | Retention cleanup |
| `aliases` | `(domain_id, normalized_alias, status)` | Collision prevention |
| `usage_records` | `(owner_type, owner_id, metric_key, period_start)` | Quota lookup |
| `audit_logs` | `(event_type, created_at)` | Admin audit search |
| `analytics_events` | `(event_type, occurred_at)` | Operational dashboards |
| `analytics_events` | `(owner_type, owner_id, occurred_at)` | Per-tenant metrics |

### Index Monitoring

- Review slow query log weekly in staging/production.
- Use `EXPLAIN` for all new high-volume queries before release.
- Drop unused indexes identified by performance_schema (post-launch).

---

## 11. Composite Index Rules

### Column Order Rules

1. **Equality filters first**, then **range filters**, then **sort column**.
2. Example: query `WHERE inbox_id = ? AND status = ? ORDER BY received_at DESC` → index `(inbox_id, status, received_at)`.
3. Example: query `WHERE status = ? AND expires_at < ?` → index `(status, expires_at)`.

### Covering Index Policy

- Consider covering indexes for hot read paths only after EXPLAIN shows significant benefit.
- Message listing may cover `(inbox_id, received_at, id, public_id, status, subject_hash)` if profiling justifies size cost — defer until measured.

### Unique Composite Indexes

| Table | Columns | Condition |
|-------|---------|-----------|
| `team_members` | `(team_id, user_id)` | One membership per pair |
| `plan_entitlements` | `(plan_id, entitlement_key)` | One entitlement key per plan |
| `settings` | `(scope_type, scope_id, key)` | One setting per scope |
| `aliases` | `(domain_id, normalized_alias)` | Active reservation — enforce active-only uniqueness in application or partial index strategy |

### Anti-Patterns

- Indexing `(received_at, inbox_id)` when queries always filter `inbox_id` first.
- Separate indexes on every column of a composite query instead of one purposeful composite.
- Adding indexes to low-cardinality columns alone (`is_active`) without composite context.

---

## 12. JSON Column Usage Policy

### When JSON Is Appropriate

Use MySQL `JSON` columns for **structured, variable-shape, low-query-frequency metadata**:

| Use case | Column example | Reason |
|----------|----------------|--------|
| Admin settings value | `settings.value` | Schema-flexible configuration |
| Domain routing metadata | `domains.routing_metadata` | Provider-specific keys |
| Audit event context | `audit_logs.metadata` | Variable event payload |
| Plan entitlement parameters | `plan_entitlements.parameters` | Limit values, feature flags |
| Ingestion provider payload summary | Staging table `normalized_headers` | Semi-structured MIME metadata |
| API token scopes | `api_tokens.scopes` | Array of scope strings |

### When JSON Is Forbidden

- Message bodies (plain text, HTML) — use TEXT or object storage references.
- Fields used in high-frequency WHERE clauses without generated columns.
- Fields requiring foreign key relationships.
- Large binary or attachment content.
- Replacing normalized relational design for convenience.

### JSON Implementation Rules

1. Validate JSON shape at application boundary (DTO or cast rules) before insert.
2. Use Laravel `AsArrayObject` or `AsCollection` casts — not manual `serialize()`.
3. If a JSON path is queried repeatedly, add a **generated stored column** + index rather than full JSON scan.
4. Keep JSON documents small (< 16 KB typical); large payloads belong in object storage.
5. Document expected JSON schema in module DTOs, not only in migrations.
6. Version JSON structures when breaking changes occur (`schema_version` field inside JSON or separate column).

---

## 13. ENUM Strategy

### Decision: PHP Enum Primary, Database String Storage

| Layer | Approach |
|-------|----------|
| Application | PHP 8.4 backed enums in `app/Enums/` and `app/Features/{Feature}/Enums/` |
| Database | `VARCHAR` (or `CHAR` for fixed length) storing enum string value |
| MySQL native ENUM | **Avoid** for application state |

### Why Not MySQL ENUM Type

- Adding new values requires DDL migration and table rebuild on large tables.
- MySQL ENUM ordering and portability issues complicate Laravel migrations.
- PHP enums provide type safety, IDE support, and Filament select integration.
- String columns allow forward-compatible values with application-level validation during deploy windows.

### Recommended Enum Categories

```text
InboxStatus, MessageStatus, DomainStatus, AliasType,
AttachmentStatus, ScanVerdict, SubscriptionStatus,
InvoiceStatus, AuditEventType, AbuseSeverity, QuarantineReason,
ApiTokenStatus, RetentionAction, PlanInterval
```

### Database Column Pattern

- Column name: `status`, `event_type`, `verdict`, etc.
- Type: `VARCHAR(32)` or appropriate max length.
- Always index status columns used in filters.
- Composite indexes place `status` after high-selectivity leading columns.

### Enum Migration Rules

1. Add new enum case in PHP first.
2. Deploy application that accepts both old and new values.
3. Migrate data if renaming values.
4. Remove deprecated values only after data backfill confirmation.

---

## 14. Audit Strategy

### Audit Table: `audit_logs`

Purpose: security, compliance, admin accountability, billing disputes, support investigation.

### What Must Be Audited

| Category | Examples |
|----------|----------|
| Authentication | Login failure spikes, password reset, role change |
| Admin actions | Domain disable, plan change, user impersonation, settings update |
| Sensitive reads | Message body view by staff (elevated access) |
| API | Token create/revoke, quota exceeded |
| Inbox lifecycle | Create, expire, block, delete |
| Billing | Subscription change, invoice adjustment |
| Abuse | Quarantine, blocklist change |

### Audit Row Design

| Column | Purpose |
|--------|---------|
| `id` | Internal BIGINT |
| `event_type` | String backed by enum |
| `actor_type`, `actor_id` | Polymorphic actor (user, system, api_token) |
| `subject_type`, `subject_id` | Polymorphic subject |
| `team_id` | Optional team context |
| `ip_hash`, `user_agent_hash` | Privacy-safe request context |
| `metadata` | JSON — redacted, no message bodies |
| `correlation_id` | Trace across services |
| `created_at` | Immutable timestamp — no `updated_at` |

### Audit Rules

1. **Append-only** during active retention — no updates or soft deletes on audit rows in hot storage.
2. Redact sensitive fields before write (`Audit` module responsibility).
3. Never store full message bodies, tokens, or passwords in audit metadata.
4. Admin Filament must support filtering by `event_type`, actor, date range.
5. Archive per Section 8; legal hold flags prevent purge when required.

### Separation from Analytics

Audit logs are authoritative for security. They are not a substitute for product analytics and vice versa.

---

## 15. Activity Log Strategy

### Distinction: Audit vs Activity

| Aspect | Audit log | Activity log |
|--------|-----------|--------------|
| Purpose | Security, compliance, accountability | Product UX, user history, operational timeline |
| Audience | Admin, security, legal | End user dashboard, support |
| Mutability | Append-only | Append-only |
| Sensitivity | High — strict redaction | Medium — no message content |
| Table | `audit_logs` | `inbox_events`, optional `user_activity_events` |

### Inbox Activity: `inbox_events`

Track inbox lifecycle for product and support:

- Created, renewed, expired, blocked, deleted, accessed (if policy allows).

Columns: `inbox_id`, `event_type`, `actor_user_id` (nullable), `metadata` (JSON), `created_at`.

### User Activity (Future)

If user-facing activity feeds are required, use a dedicated `user_activity_events` table — do not overload `audit_logs`.

### Activity Log Rules

1. No message bodies or attachment content in activity metadata.
2. Prune or archive with inbox retention unless user account history entitlement extends window.
3. Emit activity events asynchronously when possible (listeners → jobs) to avoid blocking HTTP paths.

---

## 16. Timestamp Rules

### Standard Laravel Timestamps

| Column | Usage |
|--------|-------|
| `created_at` | Row creation — immutable |
| `updated_at` | Last mutation — omit for append-only tables |
| `deleted_at` | Soft delete marker |

### Domain-Specific Timestamps

| Column | Table examples | Purpose |
|--------|----------------|---------|
| `expires_at` | `inboxes`, `messages`, `attachments`, tokens | Retention and cleanup |
| `received_at` | `messages` | Inbound email time (from headers or ingestion) |
| `processed_at` | `messages` | Parsing pipeline completion |
| `purged_at` / `body_purged_at` | `messages`, `message_bodies` | Body removal tracking |
| `read_at` | `notifications` | Laravel notification read state |
| `revoked_at` | `api_tokens` | Token revocation |
| `renews_at` | `subscriptions` | Billing renewal |
| `occurred_at` | `analytics_events`, ad metrics | Event time (may differ from insert time) |
| `scanned_at` | `attachment_scans` | Scan completion |
| `archived_at` | Archive workflow | Hot → warm transition |

### Timestamp Implementation Rules

1. Store all timestamps in **UTC** (`TIMESTAMP` or `DATETIME` — pick one project-wide; recommend `TIMESTAMP` for Laravel default consistency).
2. Laravel casts: `'datetime'` with timezone awareness in application layer.
3. API JSON output: ISO 8601 UTC (`2026-07-17T14:30:00Z`) per `CODING_STANDARDS.md`.
4. Index any timestamp used in range queries or cleanup (`expires_at`, `received_at`, `created_at`).
5. Append-only tables (`audit_logs`, `analytics_events`, `inbox_events`) use `created_at` or `occurred_at` only — no `updated_at`.

---

## 17. Multi-Domain Data Isolation

"Multi-domain" refers to **inbound email domains** (`domains` table) and future **tenant/team isolation** — not DNS multi-tenancy alone.

### Domain-Level Isolation

| Mechanism | Implementation |
|-----------|----------------|
| Inbox addressing | Every inbox belongs to exactly one `domain_id` |
| Alias uniqueness | Scoped to `(domain_id, normalized_alias)` |
| Domain policies | `domains` columns + JSON metadata for public/premium/blocked patterns |
| Ingestion routing | Resolve recipient domain → `domains.id` before inbox lookup |
| Analytics | Include `domain_id` on events where volume attribution per domain is required |

### Team / Tenant Isolation

| Mechanism | Implementation |
|-----------|----------------|
| Ownership | `inboxes.user_id`, `inboxes.team_id`, `api_tokens.team_id` |
| Query scoping | All user/team queries filter by authenticated owner — never rely on obscurity |
| Settings scope | `(scope_type, scope_id, key)` on `settings` table |
| Admin bypass | Explicit policy + audit for cross-tenant admin views |

### Data Isolation Rules

1. Public inbox URLs must not reveal other domains' data through sequential IDs (use `public_id` or address token).
2. Cross-domain joins in reporting must be admin-only or aggregated.
3. Future custom team domains link via `domains.team_id` (nullable) without merging inbox namespaces.
4. Cache keys must include domain or tenant scope to prevent leakage.

---

## 18. Queue-Related Tables

### Laravel Standard Tables (Required)

| Table | Purpose |
|-------|---------|
| `jobs` | Active queue payloads |
| `job_batches` | Batch job tracking |
| `failed_jobs` | Dead letter inspection and retry |

Use Laravel defaults unless operational requirements mandate customization.

### Queue Configuration Alignment

Queue names per `CODING_STANDARDS.md`:

```text
mail-ingestion, mail-parsing, mail-storage, attachment-scanning,
abuse-analysis, retention, notifications, analytics, billing,
webhooks, exports, default
```

Database queue driver is acceptable for local development; **Redis** (`database` connection disabled in production) is the production default per architecture docs.

### Application Queue Support Tables (Optional, Feature-Driven)

| Table | Purpose | When to create |
|-------|---------|----------------|
| `ingestion_payloads` | Staging inbound raw payload metadata before processing | Feature 4: Receive Email |
| `webhook_deliveries` | Outbound webhook retry state | Future API webhooks |
| `export_jobs` | Large admin export progress | Admin exports feature |

### Queue Table Rules

1. Staging tables store **metadata and storage references** — not full MIME bodies in MySQL at scale.
2. Include `correlation_id`, `status`, `attempts`, `available_at`, `created_at`.
3. Hard delete processed staging rows after successful promotion.
4. Index `(status, available_at)` for worker polling patterns.
5. Failed ingestion rows move to quarantine or `failed_jobs` — not indefinite staging growth.

---

## 19. Subscription-Related Tables

### Core Billing Schema

| Table | Responsibility |
|-------|----------------|
| `plans` | Plan definitions with stable `slug` |
| `plan_entitlements` | Feature limits and capability flags |
| `subscriptions` | User or team subscription state |
| `subscription_items` | Line items for multi-product plans (future) |
| `invoices` | Invoice metadata and payment state |
| `usage_records` | Metered usage for quota enforcement |
| `billing_customers` | Payment provider customer reference (per ER design) |

### Subscription Data Rules

1. **Entitlements drive limits** — not hard-coded roles (`FEATURE_ROADMAP.md` Feature 7).
2. Store provider IDs (`provider_subscription_id`, `provider_customer_id`, `provider_invoice_id`) for sync.
3. Subscription state uses PHP enum → string column (`active`, `past_due`, `cancelled`, `trialing`).
4. Never hard delete `invoices` or paid `subscriptions` — soft delete + archive.
5. `usage_records` roll up after period close; granular rows are pruned post-aggregation.
6. Plan changes snapshot effective entitlements at subscription period boundaries when billing disputes require point-in-time accuracy (future: `subscription_entitlement_snapshots`).

### Index Priorities

- `subscriptions (user_id, status)`, `(team_id, status)`, `(renews_at)`
- `usage_records (owner_type, owner_id, metric_key, period_start)`
- `invoices (subscription_id, status)`, `(provider_invoice_id)`

---

## 20. Analytics-Related Tables

### Raw Events: `analytics_events`

High-volume append-only inserts.

| Column | Notes |
|--------|-------|
| `event_type` | Indexed — e.g., `inbox.created`, `message.received` |
| `occurred_at` | Event timestamp |
| `owner_type`, `owner_id` | Optional polymorphic owner |
| `domain_id`, `inbox_id` | Optional dimensional FKs (logical, FK optional) |
| `dimensions` | JSON — numeric counters, category tags |
| `correlation_id` | Pipeline tracing |

**Never store**: message bodies, subjects with PII, raw email addresses (hash if needed).

### Rollups: `analytics_rollups`

Pre-aggregated metrics for Filament dashboards.

| Column | Notes |
|--------|-------|
| `metric_key` | e.g., `messages.received.count` |
| `period_type` | `hour`, `day`, `month` |
| `period_start` | Bucket start |
| `dimensions` | JSON — domain_id, plan_slug, etc. |
| `value` | BIGINT decimal as appropriate |

### Analytics Pipeline Rules

1. Record raw events asynchronously (`analytics` queue) — never block ingestion.
2. Aggregation jobs idempotent per `(metric_key, period_start, dimension_hash)`.
3. Raw events pruned after successful rollup (retention window configurable).
4. Admin dashboards read rollups first; raw events for drill-down only within short window.
5. Align event names with module boundaries for maintainability.

### Operational Metrics

Queue depth, failure rates, and ingestion latency may live in Redis/metrics backend at runtime; persist daily rollups to MySQL for historical admin charts.

---

## 21. Future Expansion Policy

### Schema Evolution Principles

1. **Additive first** — add columns and tables; avoid destructive changes in single deploy.
2. **Backward compatible deploys** — new columns nullable or defaulted; backfill before enforce.
3. **Feature flags** — gate behavior before schema depends on new data.
4. **Document ownership** — every new table maps to one module in `MODULE_BREAKDOWN.md`.

### Planned Expansions and Schema Impact

| Future feature | Schema impact |
|----------------|---------------|
| Object storage for bodies | `message_bodies.storage_driver`, `storage_path`, `storage_bucket` |
| Full-text search | External search index; optional `search_synced_at` on `messages` |
| Webhooks | `webhook_endpoints`, `webhook_deliveries` |
| Team custom domains | `domains.team_id`, verification columns |
| OTP extraction | `messages.detected_otp`, `otp_confidence` or separate `message_extractions` |
| Multi-region | `region` column on domain-scoped tables; shard planning |
| Enterprise SSO | `users.sso_provider_id` — identity external refs |
| Data warehouse | Outbound CDC or export jobs — no dual-write business logic in MySQL triggers |

### Expansion Approval Gate

No new table without:

- Module owner identified.
- Retention and index strategy defined.
- Privacy review for sensitive columns.
- Entry in `DATABASE_ER_DESIGN.md` update (separate task).

---

## 22. Database Performance Guidelines

### Query Discipline

1. **No unbounded queries** — all lists paginated (cursor preferred for `messages`).
2. **Select only required columns** — avoid `SELECT *` on wide message rows.
3. **Eager load deliberately** — prevent N+1 on inbox → messages admin views.
4. **Chunk writes** — cleanup deletes in batches of configurable size (500–5000 based on profiling).
5. **Avoid COUNT(*)** on huge tables in hot paths — use approximate counts or cached counters.

### Write Path Optimization

| Path | Guideline |
|------|-----------|
| Ingestion | Insert message metadata before body; async body storage |
| Idempotency | Unique index on `message_id_hash` + `inbox_id` or global fingerprint |
| Transactions | Short transactions — commit before queue dispatch when possible |
| Locks | Redis locks for alias collision; row locks only when necessary |

### Read Path Optimization

| Path | Guideline |
|------|-----------|
| Public inbox | Cache inbox metadata in Redis; poll messages with indexed cursor |
| API | Same indexes as web; rate limit before query |
| Admin Filament | Filtered indexes; default date range limits |

### Connection and Infrastructure

- Use read replicas for reporting queries when available (future).
- `innodb_buffer_pool_size` tuned for hot tables.
- Monitor slow queries, lock waits, and buffer pool hit rate.
- Object storage offloads BLOB pressure from InnoDB.

### Scale Targets (from PROJECT_SPECIFICATION.md)

- Millions of inbox records.
- Tens to hundreds of millions of message metadata rows over system lifetime (with retention/partitioning).
- Bursty inbound traffic without ingestion backlog growth under normal capacity.

---

## 23. Migration Writing Rules

### File Naming

Per `CODING_STANDARDS.md`:

```text
create_inboxes_table
add_expires_at_to_messages_table
add_status_expires_at_index_to_messages_table
```

### Migration Content Rules

1. **One logical change per migration** — do not mix unrelated tables.
2. **Approved design required** — must reference `DATABASE_ER_DESIGN.md` and this strategy.
3. **Reversible** — implement `down()` for rollbacks unless destructive data migration explicitly documented.
4. **Order** — create parent tables before children; indexes after data shape stable.
5. **Idempotent guards** — use `Schema::hasColumn` only when necessary for partial deploy recovery (sparingly).
6. **No business logic** — migrations define schema only; no seed data except where seeders belong.
7. **Comment exceptions** — document omitted FKs and partial unique strategies in migration comments.
8. **Index naming** — `idx_{table}_{columns}` or Laravel auto-generated; be consistent project-wide.

### Migration Review Checklist

- [ ] Table name plural snake_case
- [ ] Primary key strategy correct (BIGINT + public_id if external)
- [ ] All FK columns indexed
- [ ] Cleanup and list queries have composite indexes
- [ ] Soft deletes only where policy allows
- [ ] No MySQL ENUM types for application state
- [ ] JSON columns documented with expected shape
- [ ] No secrets or environment-specific values in migrations
- [ ] Rollback tested locally

### Data Migrations

- Large backfills run as separate commands/jobs, not blocking DDL migrations.
- Use `chunkById()` for backfill updates.
- Add columns nullable → backfill → add NOT NULL/enforce in follow-up migration.

---

## 24. Data Integrity Rules

### Application-Level Integrity (Required)

Database constraints supplement — not replace — business rules:

1. **Alias collision** — unique active alias per domain enforced by Action + lock + index.
2. **Inbox expiration** — expired inboxes reject new messages at ingestion Action.
3. **Plan limits** — entitlement checks before create inbox/message store.
4. **Idempotent ingestion** — duplicate detection via `message_id_hash` / fingerprint unique constraints.
5. **Token security** — store hash only; compare with `hash_equals`.
6. **State machines** — valid transitions enforced in Actions (InboxStatus, MessageStatus).

### Database-Level Integrity (Required Where Safe)

- FK constraints on core relationships (Section 4).
- NOT NULL on required ownership and status columns.
- UNIQUE on public identifiers, emails, token hashes, plan slugs.
- CHECK constraints sparingly (MySQL 8.0.16+) for simple invariants (e.g., `size_bytes >= 0`) when supported.

### Consistency Models

| Workflow | Consistency |
|----------|-------------|
| Inbox create + alias reserve | Strong — single transaction |
| Message ingest + parse | Eventual — queue pipeline |
| Analytics record | Eventual — async listener |
| Usage meter increment | Strong or eventual with reconciliation — document per metric |
| Audit log write | Strong — same transaction as admin action when feasible |

### Reconciliation Jobs

Periodic jobs detect:

- Orphan staging payloads past TTL.
- Messages stuck in `processing` status.
- Usage counter drift vs `usage_records`.
- Soft-deleted users with active subscriptions (billing alert).

---

## 25. Forbidden Database Practices

The following are **explicitly forbidden** in this platform:

| Forbidden practice | Reason |
|--------------------|--------|
| Exposing internal BIGINT `id` in public API or URLs | Enumeration, information leakage |
| UUID v4 as clustered primary key on high-volume tables | Index fragmentation, performance |
| MySQL ENUM for application lifecycle state | DDL rigidity at scale |
| Storing raw API tokens, access tokens, or passwords | Security |
| Storing full message bodies in `audit_logs` or `analytics_events` | Privacy |
| `SELECT *` on hot paths without justification | Performance |
| Unbounded DELETE or UPDATE without WHERE indexed range | Lock storms, replication lag |
| Foreign key CASCADE from users/messages/inboxes without retention workflow | Accidental data loss |
| Soft delete as sole retention mechanism | Storage unbounded growth |
| JSON columns for relational entities that need FK integrity | Query and integrity problems |
| Triggers for business logic | Hidden behavior, Laravel incompatibility |
| Stored procedures for domain workflows | Application logic belongs in Actions |
| Cross-module joins in migrations seeding unrelated data | Boundary violation |
| Creating migrations without approved ER design | Schema drift |
| Indexing every column | Write amplification |
| Large TEXT in primary table row without offload plan | Buffer pool pollution |
| Logging message bodies to MySQL error tables | Privacy |
| Relying on database alone for abuse rate limits | Use Redis throttling |
| Mixing unrelated schema changes in one migration | Rollback risk |
| Hard delete of invoices or audit records without legal review | Compliance violation |
| Using float for monetary values | Use integer minor units or DECIMAL |
| Duplicate table naming (`email` vs `message`) | Consistency — use `messages` per ER design |

---

## Implementation Readiness Checklist

Before any migration is written for a table:

- [ ] Entity documented in `DATABASE_ER_DESIGN.md`
- [ ] Primary key and `public_id` strategy confirmed
- [ ] Owning module identified
- [ ] Foreign keys and cascade rules defined
- [ ] Soft delete policy confirmed
- [ ] Retention and archive behavior defined
- [ ] Indexes for list, lookup, and cleanup queries defined
- [ ] Enum mapping to PHP enum documented
- [ ] JSON columns have schema documentation
- [ ] Privacy review for sensitive columns complete
- [ ] Queue/async interaction documented
- [ ] Migration naming follows `CODING_STANDARDS.md`

---

## Document Maintenance

Update this strategy when:

- Scale thresholds trigger partitioning or read replica adoption.
- Billing provider integration adds required columns.
- Privacy policy changes retention or erasure behavior.
- New modules add tables defined in `DATABASE_ER_DESIGN.md`.

Related documents:

- `DATABASE_ER_DESIGN.md` — entity relationships and per-table notes
- `CODING_STANDARDS.md` — naming and migration file conventions
- `PROJECT_SPECIFICATION.md` — scale and non-functional requirements
- `FEATURE_ROADMAP.md` — phased table introduction order

---

*This is a strategy document. It authorizes no migrations, models, or application code by itself.*
