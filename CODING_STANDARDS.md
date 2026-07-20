# CODING STANDARDS

## Purpose

This document defines coding and naming standards for the Temporary Email SaaS.

It covers naming conventions, Laravel class naming, queue naming, configuration naming, migration naming, API response format, JSON standards, and error response standards.

This is a documentation-only specification. It does not define application code, migrations, models, or controllers.

## General Standards

- Follow Laravel 12 conventions.
- Follow PSR-12 coding style.
- Use clear business language.
- Prefer explicit names over abbreviations.
- Keep names consistent across code, database, API, queues, events, and admin UI.
- Use singular names for models and domain concepts.
- Use plural names for database tables and route collections.
- Keep business logic out of controllers.
- Use Services, Actions, DTOs, Value Objects, Events, Listeners, Jobs, and Policies where appropriate.
- Prefer composition over inheritance.
- Avoid generic names such as `Manager`, `Helper`, `Processor`, or `Handler` unless the meaning is specific and obvious.

## Naming Convention

### Classes

Use `PascalCase`.

Examples:

- `CreateInbox`
- `StoreInboundMessage`
- `MessageReceived`
- `InboxExpired`
- `InboundEmailPayload`

### Methods

Use `camelCase`.

Examples:

- `createInbox`
- `resolveDomain`
- `sanitizeHtml`
- `recordAuditEvent`

### Variables

Use `camelCase`.

Examples:

- `inboxAddress`
- `messageStatus`
- `retentionWindow`
- `apiToken`

### Database Tables

Use plural `snake_case`.

Examples:

- `users`
- `inboxes`
- `messages`
- `message_bodies`
- `attachment_scans`
- `audit_logs`

### Database Columns

Use `snake_case`.

Examples:

- `public_id`
- `inbox_id`
- `received_at`
- `expires_at`
- `deleted_at`
- `message_id_hash`

### Config Keys

Use lowercase `snake_case`.

Examples:

- `default_retention_minutes`
- `max_message_size`
- `idempotency_window`
- `attachment_scan_enabled`

### Environment Variables

Use uppercase `SNAKE_CASE` grouped by feature.

Examples:

- `INGESTION_PROVIDER`
- `INGESTION_MAX_MESSAGE_SIZE`
- `RETENTION_ANONYMOUS_INBOX_MINUTES`
- `ABUSE_MAX_INBOXES_PER_IP`
- `API_DEFAULT_RATE_LIMIT`

## Model Naming

### Rule

Models use singular `PascalCase` names matching the business entity.

Examples:

- `User`
- `Team`
- `Inbox`
- `Message`
- `MessageBody`
- `Attachment`
- `Domain`
- `Alias`
- `Plan`
- `Subscription`
- `Invoice`
- `AuditLog`

### Model Rules

- Models should remain lean.
- Models may contain relationships, casts, scopes, accessors, and simple state helpers.
- Models must not contain large business workflows.
- Models must not call external services.
- Models must not dispatch complex cross-module workflows directly.
- State transitions should be performed by Actions or Services.

## Controller Naming

### Rule

Controllers use singular or resource-oriented `PascalCase` names ending in `Controller`.

Examples:

- `InboxController`
- `MessageController`
- `ApiTokenController`
- `WebhookController`
- `InboundEmailController`

### API Controller Names

Versioned API controllers should live under versioned namespaces.

Examples:

- `Api\V1\InboxController`
- `Api\V1\MessageController`
- `Api\V1\ApiTokenController`

### Controller Rules

- Controllers must be thin.
- Controllers may validate input, authorize access, call Actions or Services, and return responses.
- Controllers must not contain business workflows.
- Controllers must not perform complex queries directly.
- Controllers must not process inbound email payloads synchronously beyond validation and dispatch.

## Service Naming

### Rule

Services use clear capability names ending in `Service` only when it improves clarity.

Examples:

- `InboxLifecycleService`
- `AliasGenerationService`
- `DomainHealthService`
- `MessageSanitizationService`
- `RetentionPolicyService`
- `AbuseScoringService`
- `ApiQuotaService`

### Service Rules

- Services should represent reusable domain or platform capabilities.
- Services must not depend on controllers.
- Services must be injectable.
- Services should coordinate lower-level collaborators, not become large procedural classes.
- Avoid vague names like `EmailService` when a more precise name exists.

## Action Naming

### Rule

Actions use verb phrases in `PascalCase`.

Examples:

- `CreateInbox`
- `RenewInbox`
- `ExpireInbox`
- `DeleteInbox`
- `ReserveAlias`
- `StoreInboundMessage`
- `ParseInboundMessage`
- `QuarantineMessage`
- `ScanAttachment`
- `RecordAuditEvent`
- `CheckApiQuota`

### Action Rules

- One action equals one use case.
- Actions may accept DTOs or Value Objects.
- Actions may return DTOs, result objects, models, or Value Objects.
- Actions may dispatch events.
- Actions must not depend on raw HTTP requests.
- Actions must not render responses.

## Event Naming

### Rule

Events use past-tense business facts in `PascalCase`.

Examples:

- `InboxCreated`
- `InboxExpired`
- `AliasReserved`
- `MessageReceived`
- `MessageParsed`
- `MessageQuarantined`
- `AttachmentScanned`
- `ApiQuotaExceeded`
- `AdminActionPerformed`
- `SettingUpdated`

### Event Rules

- Event names must describe something that already happened.
- Events must not describe commands.
- Events must not decide what listeners should do.
- Events should carry minimal required context.
- Events must avoid carrying raw sensitive email content.

## Listener Naming

### Rule

Listeners use action-oriented names that describe the reaction.

Examples:

- `RecordInboxCreatedAuditLog`
- `QueueMessageParsing`
- `RecordMessageReceivedMetric`
- `NotifyAdminsOfAbuseSpike`
- `UpdateDomainHealthSnapshot`

### Listener Rules

- Listeners should be small.
- Slow listeners should dispatch jobs.
- Listeners must be idempotent when duplicate events are possible.

## Job Naming

### Rule

Jobs use verb phrases and may end in `Job` if the team chooses explicit suffixes.

Preferred examples:

- `ParseInboundMessage`
- `StoreMessageBody`
- `ScanAttachment`
- `ExpireInboxes`
- `DeleteExpiredMessages`
- `AggregateUsageMetrics`
- `CheckDomainHealth`

Acceptable explicit suffix examples:

- `ParseInboundMessageJob`
- `ScanAttachmentJob`
- `DeleteExpiredMessagesJob`

### Job Rules

- Job names must describe the work being performed.
- Jobs must be idempotent or duplicate-safe.
- Jobs must define queue, retry, timeout, and backoff rules during implementation.
- Jobs must not rely on request state.

## Queue Naming

### Rule

Queue names use lowercase `kebab-case`.

Recommended queues:

- `mail-ingestion`
- `mail-parsing`
- `mail-storage`
- `attachment-scanning`
- `abuse-analysis`
- `retention`
- `notifications`
- `analytics`
- `billing`
- `webhooks`
- `exports`
- `default`

### Queue Priority

Recommended priority order:

1. `mail-ingestion`
2. `mail-parsing`
3. `mail-storage`
4. `attachment-scanning`
5. `abuse-analysis`
6. `notifications`
7. `analytics`
8. `retention`
9. `exports`

### Queue Rules

- High-volume mail processing must not share workers with slow exports.
- Retention jobs must not block ingestion jobs.
- Analytics jobs must tolerate delay.
- Failed jobs must include safe diagnostic context.

## Config Naming

### Config File Names

Use lowercase `snake_case` or concise feature names.

Recommended files:

- `abuse.php`
- `api.php`
- `audit.php`
- `billing.php`
- `domains.php`
- `ingestion.php`
- `retention.php`
- `security.php`
- `services.php`

### Config Key Rules

- Use lowercase `snake_case`.
- Group related settings.
- Avoid reading environment variables directly outside config files.
- Use safe defaults.
- Keep secrets out of committed config.

Example key style:

```text
ingestion.max_message_size
ingestion.idempotency_window_seconds
retention.anonymous_inbox_minutes
abuse.max_inboxes_per_ip_per_hour
api.default_rate_limit_per_minute
```

## Migration Naming

### Rule

Migration file names must describe the schema operation in `snake_case`.

Examples:

- `create_inboxes_table`
- `create_messages_table`
- `add_expires_at_to_inboxes_table`
- `add_status_index_to_messages_table`
- `create_attachment_scans_table`

### Migration Rules

- Use plural table names.
- Use descriptive column names.
- Every foreign key must have an index.
- Every high-volume query path must have an index.
- Every retention cleanup field must be indexed.
- Do not create migrations without an approved data model.
- Do not mix unrelated schema changes in one migration.

## Route Naming

### Web Route Names

Use lowercase dot notation.

Examples:

- `inboxes.show`
- `messages.show`
- `messages.delete`
- `account.settings`

### API Route Names

Use versioned dot notation.

Examples:

- `api.v1.inboxes.index`
- `api.v1.inboxes.store`
- `api.v1.messages.index`
- `api.v1.messages.show`

### Admin Route Names

Admin routes are managed by Filament panel conventions.

## API Response Format

### Success Response

All successful API responses should use a consistent envelope.

```json
{
  "success": true,
  "data": {},
  "meta": {},
  "links": {}
}
```

### Collection Response

```json
{
  "success": true,
  "data": [],
  "meta": {
    "pagination": {
      "current_page": 1,
      "per_page": 25,
      "total": 100,
      "last_page": 4
    }
  },
  "links": {
    "first": null,
    "last": null,
    "prev": null,
    "next": null
  }
}
```

### Empty Success Response

```json
{
  "success": true,
  "data": null,
  "meta": {}
}
```

### Response Rules

- `success` must always be boolean.
- `data` contains the primary response payload.
- `meta` contains pagination, rate-limit, timing, or contextual metadata.
- `links` contains pagination or related resource links when needed.
- Collections must return arrays.
- Empty resources should return `null`, not an empty object.

## JSON Standard

### Field Naming

Use `snake_case` for all JSON field names.

Examples:

- `public_id`
- `email_address`
- `created_at`
- `expires_at`
- `message_count`
- `api_quota_remaining`

### Date Format

Use ISO 8601 UTC timestamps.

Example:

```json
{
  "created_at": "2026-07-17T14:30:00Z"
}
```

### Boolean Fields

Use positive boolean names.

Examples:

- `is_active`
- `is_verified`
- `has_attachments`
- `can_renew`

Avoid negative names such as:

- `is_not_active`
- `cannot_renew`

### Identifier Fields

Expose public identifiers, not internal database IDs.

Examples:

- `public_id`
- `inbox_id`
- `message_id`

Internal numeric IDs must not be exposed unless explicitly approved for admin-only internal APIs.

### Null Handling

- Use `null` for known empty values.
- Omit fields only when the field is not applicable or not authorized.
- Do not return empty strings for missing values.

## Error Response Standard

### Error Response Format

```json
{
  "success": false,
  "error": {
    "code": "resource_not_found",
    "message": "The requested resource was not found.",
    "details": {},
    "correlation_id": "01J2EXAMPLECORRELATIONID"
  },
  "meta": {}
}
```

### Validation Error Format

```json
{
  "success": false,
  "error": {
    "code": "validation_failed",
    "message": "The given data was invalid.",
    "details": {
      "email_address": [
        "The email address field is required."
      ]
    },
    "correlation_id": "01J2EXAMPLECORRELATIONID"
  },
  "meta": {}
}
```

### Rate Limit Error Format

```json
{
  "success": false,
  "error": {
    "code": "rate_limit_exceeded",
    "message": "Too many requests. Please try again later.",
    "details": {
      "retry_after_seconds": 60
    },
    "correlation_id": "01J2EXAMPLECORRELATIONID"
  },
  "meta": {
    "rate_limit": {
      "limit": 60,
      "remaining": 0,
      "reset_at": "2026-07-17T14:31:00Z"
    }
  }
}
```

### Error Code Naming

Use lowercase `snake_case`.

Examples:

- `validation_failed`
- `unauthenticated`
- `forbidden`
- `resource_not_found`
- `rate_limit_exceeded`
- `quota_exceeded`
- `inbox_expired`
- `message_quarantined`
- `domain_unavailable`
- `alias_already_taken`
- `attachment_blocked`
- `service_unavailable`

### HTTP Status Mapping

- `200 OK` for successful reads.
- `201 Created` for successful resource creation.
- `202 Accepted` for accepted asynchronous work.
- `204 No Content` only when the API intentionally returns no body.
- `400 Bad Request` for malformed requests.
- `401 Unauthorized` for unauthenticated requests.
- `403 Forbidden` for authenticated but unauthorized requests.
- `404 Not Found` for missing or inaccessible resources.
- `409 Conflict` for state conflicts or duplicate resources.
- `422 Unprocessable Entity` for validation failures.
- `429 Too Many Requests` for rate limits.
- `500 Internal Server Error` for unexpected server failures.
- `503 Service Unavailable` for temporary infrastructure or maintenance failures.

### Error Rules

- Error messages must be safe for public display.
- Error details must not expose secrets, tokens, raw email bodies, private headers, or internal stack traces.
- Every error response should include a correlation ID.
- Validation errors should identify fields using JSON field names.
- Authorization errors should not reveal private resource existence.
- Rate-limit errors should include retry guidance when safe.

## Final Rule

Consistency is more important than personal preference. Once a naming pattern is selected for a module, future code must follow it unless an architectural review approves a change.

