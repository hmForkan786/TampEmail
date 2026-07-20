# PROJECT SPECIFICATION

## 1. Vision

Build a production-ready Temporary Email SaaS that provides fast, private, disposable inboxes for users and developers while operating reliably at very large scale. The platform must handle millions of inbound emails, support public and authenticated workflows, provide administrative control through Filament v4, and remain modular enough to evolve into an API-first privacy infrastructure product.

The system should feel instant to end users, predictable to administrators, and operationally safe for engineering teams.

## 2. Goals

- Provide disposable email inboxes with automatic expiration.
- Receive, process, store, search, and display inbound emails at high volume.
- Support anonymous users, registered users, teams, administrators, and API consumers.
- Offer a clean Filament v4 admin panel for operations, moderation, analytics, and configuration.
- Use Laravel 12, PHP 8.4, MySQL, Redis, queues, and Docker-compatible infrastructure.
- Design the system to scale horizontally across web, worker, scheduler, mail ingestion, and API services.
- Protect the platform from abuse, spam amplification, data leakage, and infrastructure exhaustion.
- Maintain a codebase that is modular, testable, observable, and suitable for long-term SaaS growth.

## 3. Scope

### In Scope

- Temporary inbox generation.
- Custom aliases and random aliases.
- Configurable email domain management.
- Inbound email ingestion.
- Email parsing and sanitization.
- Inbox viewing and message lifecycle management.
- Expiration policies for inboxes and messages.
- Public web interface.
- Authenticated dashboard.
- Filament v4 admin panel.
- API access for paid or authorized users.
- Rate limiting and abuse protection.
- Redis-backed caching, throttling, locks, and queues.
- Queue-based email processing.
- MySQL persistence.
- Docker-ready local and production deployment structure.
- Observability, logging, metrics, and audit trails.

### Out of Scope for Initial Release

- Sending outbound emails from temporary inboxes.
- Full email client features such as folders, labels, replies, forwarding, signatures, and contacts.
- End-to-end encrypted mailbox storage.
- Native mobile applications.
- Enterprise SSO.
- Marketplace integrations.

These may be considered future expansion items.

## 4. User Roles

### Anonymous Visitor

- Can create or access a temporary inbox.
- Can read received messages for that inbox.
- Can copy the generated email address.
- Can delete or refresh an inbox when permitted.
- Is subject to strict rate limits and shorter retention periods.

### Registered User

- Can manage multiple temporary inboxes.
- Can reserve aliases where allowed.
- Can use longer retention windows based on plan.
- Can access account history where privacy settings allow.
- Can manage API tokens if included in plan.

### Team Owner

- Can manage team members.
- Can manage shared domains or aliases.
- Can view usage, billing-related metrics, and API consumption.
- Can configure retention and access policies within plan limits.

### Team Member

- Can access team inboxes according to assigned permissions.
- Can use team API credentials when authorized.

### API Consumer

- Can create inboxes, poll messages, fetch message content, and delete resources through authenticated API endpoints.
- Is governed by API-specific quotas and abuse controls.

### Support Operator

- Can inspect operational metadata.
- Can assist with user account and billing support.
- Cannot read message bodies unless explicitly granted through audited elevated access.

### Administrator

- Can manage users, teams, plans, domains, aliases, inbound routing, abuse rules, queues, and system settings.
- Can view analytics and operational health.
- Can take moderation actions.

### Super Administrator

- Has full platform authority.
- Can manage administrators, sensitive settings, domain ownership, data retention policies, and emergency controls.

## 5. Functional Requirements

### Inbox Management

- The system must generate temporary email addresses using configured domains.
- The system must support both random aliases and controlled custom aliases.
- The system must prevent duplicate active aliases on the same domain.
- The system must support configurable inbox expiration.
- The system must allow inbox deletion.
- The system must support inbox renewal when allowed by plan or policy.
- The system must show inbox status: active, expired, blocked, deleted, or reserved.

### Domain Management

- Administrators must be able to add and disable email domains.
- Domains must support verification status.
- Domains must support routing configuration metadata.
- Domains must support health status.
- Domains must support per-domain rules such as public availability, premium-only use, and blocked alias patterns.

### Email Ingestion

- The platform must accept inbound emails through a scalable ingestion layer.
- Inbound email processing must be asynchronous.
- Raw inbound payloads must be validated before persistence.
- Messages must be associated with the correct active inbox.
- Messages for unknown, expired, blocked, or rate-limited inboxes must be rejected, discarded, quarantined, or logged according to policy.
- The ingestion layer must be idempotent.
- Duplicate messages must be detected where possible using message identifiers and content fingerprints.

### Email Parsing

- The system must parse sender, recipients, subject, date, plain text body, HTML body, attachments, headers, and metadata.
- HTML content must be sanitized before display.
- Unsafe scripts, tracking behaviors, embedded forms, and dangerous links must be neutralized or clearly handled.
- Attachments must be scanned, size-limited, typed, and optionally disabled by policy.
- Large messages must be handled without blocking web requests.

### Message Viewing

- Users must be able to view an inbox and its messages.
- Users must be able to open individual messages.
- Users must be able to see message metadata.
- Users must be able to delete messages where policy allows.
- The UI must support polling, server-sent events, WebSockets, or another scalable refresh strategy.

### Search and Filtering

- Registered users and administrators must be able to filter messages by inbox, domain, sender, status, date, and abuse markers.
- Full-text search may be limited or delegated to a future search service for large-scale deployments.

### Retention and Cleanup

- Expired inboxes must no longer receive accessible messages.
- Expired messages must be deleted or anonymized according to retention policy.
- Cleanup must run through scheduled jobs and queue workers.
- Deletion must be safe, batched, observable, and resumable.

### Abuse Protection

- The system must rate-limit inbox creation, inbox access, API calls, and suspicious recipient patterns.
- The system must detect high-volume abuse by IP, user, domain, alias, ASN, user agent, and token.
- The system must support blocked aliases, blocked senders, blocked domains, and quarantined messages.
- The system must expose abuse controls in Filament.

### Authentication and Accounts

- The system must support registered accounts.
- Authentication must be compatible with Laravel 12 best practices.
- Admin authentication must be separated from public user access where appropriate.
- API tokens must be revocable and scoped.

### Plans and Limits

- The platform must support plan-based limits.
- Limits may include inbox count, message retention, custom aliases, API requests, domains, attachment access, and polling frequency.
- Billing implementation may be deferred, but the architecture must support it.

### API

- The API must support authenticated creation and management of temporary inboxes.
- The API must support message listing, retrieval, and deletion.
- API responses must be versioned.
- API consumers must receive clear error codes and rate-limit headers.
- API access must be separately observable from web usage.

### Filament Admin Panel

- Administrators must manage users, teams, domains, inboxes, messages, aliases, abuse rules, plans, system settings, and operational events.
- Filament resources must be organized by business capability.
- Destructive admin actions must require confirmation.
- Sensitive reads and writes must be audited.
- Admin dashboards must expose operational health, queue status, inbound volume, abuse trends, and storage growth.

### Observability

- The platform must log important business and security events.
- Metrics must cover inbound email volume, processing latency, queue depth, failures, API usage, cache hit rate, storage growth, and cleanup throughput.
- Errors must include correlation identifiers.
- Long-running jobs must expose progress and failure context.

## 6. Non Functional Requirements

### Scalability

- The system must scale horizontally.
- Web traffic, mail ingestion, queue workers, scheduled jobs, and admin workloads must be deployable as separate process types.
- The design must support millions of stored messages and high inbound throughput.
- Queue processing must be partitionable by workload type.

### Reliability

- Inbound email processing must be durable.
- Jobs must be retryable and idempotent.
- Failures must not corrupt inbox or message state.
- Scheduled cleanup must be resumable.
- The system must degrade gracefully during high traffic.

### Maintainability

- Business logic must be organized by feature module.
- Controllers and Filament resources must remain thin.
- Shared infrastructure concerns must not leak into domain logic.
- Tests must cover critical business flows and failure paths.

### Privacy

- Temporary email content must be treated as sensitive.
- Retention defaults must favor minimization.
- Message body access by staff must be restricted and audited.
- Logs must not expose full message bodies, secrets, tokens, or private headers.

### Compliance Readiness

- The system must support data deletion.
- The system must support export of account-level metadata where applicable.
- Retention policies must be explicit.
- Administrative access must be auditable.

### Portability

- The project must be Docker ready.
- Environment-specific configuration must be externalized.
- Local development, staging, and production must use similar service topology.

## 7. Folder Organization Philosophy

The project should be organized around business capabilities rather than technical layers alone. Laravel conventions should remain recognizable, but domain complexity should live in cohesive modules.

Recommended philosophy:

- Keep framework entry points familiar.
- Group feature-specific actions, data objects, policies, services, jobs, events, listeners, and tests together where practical.
- Keep Filament admin resources separate from public user interfaces.
- Separate ingestion, parsing, retention, abuse, billing, and API concerns.
- Keep infrastructure adapters replaceable.
- Avoid placing large business workflows directly inside controllers, models, commands, or Filament resources.

Suggested high-level areas:

- `App/Features/Inbox`
- `App/Features/Message`
- `App/Features/Ingestion`
- `App/Features/Domain`
- `App/Features/Alias`
- `App/Features/Abuse`
- `App/Features/Retention`
- `App/Features/Api`
- `App/Features/Billing`
- `App/Features/Audit`
- `App/Filament/Admin`
- `App/Support`
- `App/Infrastructure`

This is a philosophy, not a command to create files immediately.

## 8. Coding Standards

- Follow Laravel 12 conventions unless there is a clear architectural reason not to.
- Use PHP 8.4 language features responsibly.
- Use strict typing where practical.
- Keep methods small and intention-revealing.
- Prefer explicit dependencies over hidden global state.
- Use constructor injection or Laravel-supported dependency injection.
- Keep controllers thin.
- Keep jobs focused and retry-safe.
- Keep commands orchestration-focused.
- Keep Filament resources focused on admin presentation and actions.
- Use value objects or data transfer objects for complex structured input.
- Use enums for stable state machines and controlled option sets.
- Use policies for authorization.
- Use form requests or equivalent validation boundaries for user input.
- Avoid business logic in Blade views.
- Avoid unbounded queries.
- Avoid synchronous heavy processing in HTTP requests.
- Avoid logging sensitive email content.
- Write tests for feature behavior, permissions, queues, retention, and abuse controls.

## 9. Naming Convention

### General

- Use clear business language.
- Prefer names that describe intent rather than implementation.
- Avoid abbreviations unless they are industry-standard.
- Keep names consistent across database, code, routes, queues, events, and UI.

### Feature Names

- Singular capability names are preferred for modules: `Inbox`, `Message`, `Domain`, `Alias`, `Abuse`, `Retention`.

### Classes

- Actions should use verb phrases: `CreateInbox`, `ExpireInbox`, `StoreInboundMessage`.
- Jobs should end with `Job` only if the project standard requires explicit suffixes.
- Events should use past-tense business facts: `MessageReceived`, `InboxExpired`.
- Listeners should describe reactions: `QueueMessageParsing`, `RecordAbuseSignal`.
- Policies should match protected resources.
- Filament resources should match admin-managed concepts.

### Routes

- Public web routes should be human-readable.
- API routes must be versioned.
- Admin routes must live under the Filament panel path.

### Queues

Queue names should represent workload priority and type:

- `mail-ingestion`
- `mail-parsing`
- `mail-storage`
- `notifications`
- `retention`
- `abuse-analysis`
- `exports`
- `default`

### Configuration

- Environment variables must be uppercase and grouped by concern.
- Config keys must use lowercase snake case.
- Sensitive configuration must never be committed.

## 10. Architecture Principles

- Design for horizontal scale from the beginning.
- Treat inbound email ingestion as a pipeline.
- Use queues for all expensive or failure-prone processing.
- Use Redis for cache, locks, throttling, short-lived state, and queue infrastructure where appropriate.
- Use MySQL as the durable source of truth.
- Prefer idempotent operations.
- Prefer explicit state machines for inboxes and messages.
- Optimize reads and writes separately where necessary.
- Keep public, authenticated, API, and admin concerns separate.
- Preserve Laravel conventions while isolating domain complexity.
- Use database indexes intentionally.
- Use batch processing for cleanup and archival.
- Avoid coupling the product to a single email provider.
- Keep observability as a first-class architectural concern.
- Assume abuse will happen.

## 11. Feature Modules

### Inbox Module

Responsibilities:

- Generate inboxes.
- Resolve inbox ownership.
- Track inbox status.
- Enforce expiration.
- Enforce plan limits.
- Provide inbox read models for web and API.

### Alias Module

Responsibilities:

- Generate aliases.
- Reserve aliases.
- Validate custom aliases.
- Prevent collisions.
- Enforce blocked patterns.

### Domain Module

Responsibilities:

- Manage inbound domains.
- Track domain status and configuration.
- Support domain-level availability and limits.
- Provide routing metadata to ingestion services.

### Ingestion Module

Responsibilities:

- Receive inbound email payloads.
- Validate recipient addresses.
- Normalize inbound data.
- Dispatch parsing and storage work.
- Provide idempotency and failure handling.

### Message Module

Responsibilities:

- Persist message metadata and content.
- Present sanitized message content.
- Manage attachment metadata.
- Support deletion and retention workflows.

### Parsing Module

Responsibilities:

- Parse MIME content.
- Extract text, HTML, headers, recipients, and attachments.
- Sanitize dangerous content.
- Detect malformed or suspicious messages.

### Abuse Module

Responsibilities:

- Rate-limit usage.
- Detect suspicious patterns.
- Manage blocklists and allowlists.
- Quarantine messages.
- Feed administrative moderation workflows.

### Retention Module

Responsibilities:

- Expire inboxes.
- Delete expired messages.
- Archive or anonymize data where required.
- Run safe batched cleanup.

### User and Team Module

Responsibilities:

- Manage accounts.
- Manage teams and permissions.
- Enforce ownership and access rules.
- Support plan-based capabilities.

### API Module

Responsibilities:

- Version public API endpoints.
- Authenticate tokens.
- Enforce scopes and quotas.
- Return consistent API errors.
- Emit API usage metrics.

### Admin Module

Responsibilities:

- Provide Filament v4 resources.
- Manage operational data.
- Support moderation.
- Expose analytics and health dashboards.
- Audit sensitive admin actions.

### Audit Module

Responsibilities:

- Record important user, admin, API, and system events.
- Preserve security-relevant trails.
- Support investigation without exposing unnecessary message content.

### Billing Module

Responsibilities:

- Represent plans and limits.
- Prepare for subscriptions, invoices, metering, and entitlements.
- Integrate with payment providers in future phases.

## 12. Performance Targets

### Web

- Public inbox page initial response: under 300 ms at p95 under normal load.
- Authenticated dashboard response: under 500 ms at p95 under normal load.
- Admin panel list pages: under 800 ms at p95 with proper filtering and pagination.

### API

- API read endpoints: under 250 ms at p95 for cached or indexed reads.
- API write endpoints: under 400 ms at p95 excluding asynchronous processing.
- Rate-limit checks: under 20 ms at p95.

### Email Ingestion

- Inbound acceptance latency: under 200 ms p95 before queue dispatch where provider flow permits.
- Message parse completion: under 5 seconds p95 for normal-sized messages.
- End-to-end inbox visibility: under 10 seconds p95.

### Queue

- Queue workers must scale horizontally.
- Critical mail ingestion queues should maintain low backlog under expected traffic.
- Failed jobs must remain below an agreed operational threshold.
- Retry storms must be controlled with backoff and circuit-breaking behavior.

### Storage

- All high-cardinality query paths must be indexed.
- Message listings must use pagination or cursor-based pagination.
- Cleanup jobs must operate in bounded batches.
- Large body and attachment storage must be designed to avoid excessive database pressure.

### Scale Targets

- Support millions of inbox records.
- Support hundreds of millions of message metadata records over time with retention and partitioning strategy.
- Support bursty inbound email traffic.
- Support multiple worker pools.
- Support read-heavy public inbox traffic through caching and efficient polling.

## 13. Security Principles

- Treat all inbound email as untrusted input.
- Sanitize all rendered HTML.
- Disable or proxy unsafe remote content by default.
- Never execute email content.
- Enforce CSRF protection for browser flows.
- Enforce authentication and authorization consistently.
- Separate admin and user permissions.
- Use least privilege for infrastructure credentials.
- Store secrets only in environment-managed secret stores.
- Hash API tokens.
- Rate-limit public and API endpoints.
- Use Redis locks for race-prone alias and inbox creation.
- Audit sensitive admin actions.
- Avoid exposing whether a private or reserved alias exists unless policy allows.
- Prevent enumeration of inboxes and messages.
- Protect against SSRF through remote content handling.
- Protect against stored XSS from message bodies and filenames.
- Protect against oversized payloads.
- Protect against ZIP bombs and dangerous attachments.
- Keep dependencies patched.
- Use secure cookie and session settings in production.
- Enforce HTTPS in production.

## 14. Future Expansion Plan

### Product Expansion

- Paid plans.
- Team workspaces.
- Custom domains for teams.
- Browser extensions.
- API usage analytics.
- Webhooks for received messages.
- Email forwarding rules.
- Attachment sandboxing.
- Advanced alias reservation.
- Private inbox links.
- Inbox sharing.
- Enterprise audit exports.

### Technical Expansion

- Dedicated search service for message metadata.
- Object storage for message bodies and attachments.
- Database read replicas.
- Table partitioning or sharding by time, domain, or tenant.
- Dedicated mail ingestion service.
- WebSocket or event-stream infrastructure.
- Data warehouse or analytics pipeline.
- Multi-region deployment.
- Provider-agnostic inbound email adapters.
- Advanced abuse detection using scoring and machine learning.

### Operational Expansion

- Blue-green deployments.
- Automated backup verification.
- Disaster recovery runbooks.
- Load testing pipeline.
- Security testing pipeline.
- Admin emergency controls.
- Incident response dashboards.

## 15. Risks

### Abuse Risk

Temporary email services are frequently abused for spam, fraud, evasion, and account farming. Strong rate limits, blocklists, provider controls, and monitoring are required from day one.

### Storage Growth Risk

Email content can grow quickly. Retention policies, cleanup jobs, attachment limits, and storage architecture must be enforced early.

### Deliverability and Provider Risk

Inbound email routing depends on DNS, MX configuration, and provider behavior. The architecture must avoid lock-in and expose domain health clearly.

### Privacy Risk

Message content may contain sensitive information. Staff access, logs, exports, and analytics must be carefully limited.

### Performance Risk

Polling inboxes and processing inbound bursts can create high load. Caching, rate limits, queue isolation, and efficient indexes are essential.

### Legal and Compliance Risk

Temporary email may be restricted or disallowed by some third-party platforms. The SaaS must have terms of service, abuse response processes, and data handling policies.

### Operational Risk

Queue failures, stuck cleanup jobs, full disks, slow queries, and Redis outages can degrade the system. Observability and runbooks are required.

### Security Risk

Rendering hostile email content creates XSS, SSRF, and malware risks. Sanitization and attachment handling must be treated as core security features.

## 16. Development Rules

- Do not write production code without a linked requirement.
- Do not create migrations without an approved data model.
- Do not create models before defining ownership, lifecycle, indexes, and retention behavior.
- Do not place business workflows directly in controllers or Filament resources.
- Do not run heavy processing inside HTTP requests.
- Do not introduce unbounded queries.
- Do not store raw secrets in source control.
- Do not log message bodies, full headers, API tokens, or credentials.
- Do not expose admin-only data in public APIs.
- Do not bypass policies or authorization checks.
- Do not render unsanitized email HTML.
- Do not add new queue workloads without naming, retry, timeout, and failure rules.
- Do not add scheduled tasks without batching and observability.
- Do not add external services without configuration, timeout, retry, and failure strategy.
- Do not add dependencies without evaluating maintenance, security, and licensing.
- Every feature must define validation rules.
- Every feature must define authorization rules.
- Every feature must define expected failure behavior.
- Every feature must define observability requirements.
- Every high-volume feature must define indexes and caching strategy before implementation.
- Every destructive admin action must be confirmed and audited.
- Every public endpoint must be rate-limited.
- Every API endpoint must be versioned.
- Every background job must be idempotent or explicitly protected against duplicate effects.
- Every production deployment must pass automated tests, static analysis, formatting checks, and security-sensitive review.

