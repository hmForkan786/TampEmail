# PROJECT ARCHITECTURE

## Purpose

This document defines the folder architecture for the Temporary Email SaaS described in `PROJECT_SPECIFICATION.md`.

The architecture follows Laravel 12 conventions while organizing business complexity into clear feature modules. It is designed for a high-volume SaaS that must process millions of emails through web, API, queue, scheduler, and admin surfaces.

This document is a design specification only. It does not create application code, migrations, models, or implementation files.

## Core Architecture Philosophy

- Laravel remains the framework foundation.
- Business capability owns business behavior.
- Controllers, Filament resources, commands, and jobs coordinate work but do not contain core business logic.
- Services handle reusable domain operations.
- Actions represent explicit use cases.
- DTOs carry validated structured data across boundaries.
- Value Objects represent meaningful immutable domain concepts.
- Events describe facts that happened.
- Listeners react to events without coupling the source feature to downstream behavior.
- Jobs handle asynchronous, retryable, idempotent work.
- Repositories are used only where they hide meaningful persistence complexity.
- Traits are limited to narrow, stable, reusable behavior.

## 1. Root Folder Organization

```text
email-app/
├── app/
├── bootstrap/
├── config/
├── database/
├── docker/
├── docs/
├── lang/
├── public/
├── resources/
├── routes/
├── storage/
├── tests/
├── vendor/
├── .env
├── .env.example
├── artisan
├── composer.json
├── docker-compose.yml
├── Dockerfile
├── phpunit.xml
└── README.md
```

### Why Each Root Folder Exists

- `app/` contains Laravel application code, business modules, framework integrations, and infrastructure adapters.
- `bootstrap/` contains Laravel bootstrap files and provider registration.
- `config/` contains environment-driven configuration for platform behavior, mail ingestion, queues, retention, abuse controls, and integrations.
- `database/` contains migrations, seeders, factories, and database-related development assets when implementation begins.
- `docker/` contains Docker service configuration for PHP, Nginx, workers, scheduler, Redis, MySQL, and mail ingestion support.
- `docs/` contains supporting architectural, operational, API, security, and deployment documentation.
- `lang/` contains translation files for user-facing and admin-facing text.
- `public/` contains the HTTP entry point and published public assets.
- `resources/` contains Blade views, frontend assets, CSS, JavaScript, and email notification templates.
- `routes/` contains web, API, console, and integration route definitions.
- `storage/` contains runtime-generated files, logs, cache files, temporary files, and local private storage.
- `tests/` contains automated tests grouped by behavior and architectural layer.
- `vendor/` contains Composer dependencies and must not be modified manually.

## 2. App Directory Structure

```text
app/
├── Actions/
├── Console/
├── Contracts/
├── DTOs/
├── Enums/
├── Events/
├── Exceptions/
├── Features/
├── Filament/
├── Helpers/
├── Http/
├── Infrastructure/
├── Jobs/
├── Listeners/
├── Mail/
├── Models/
├── Notifications/
├── Policies/
├── Providers/
├── Repositories/
├── Services/
├── Support/
├── Traits/
└── ValueObjects/
```

### Why Each App Folder Exists

- `Actions/` contains cross-feature use cases or application-level orchestration that does not belong to one module.
- `Console/` contains Laravel commands for operational tasks, diagnostics, maintenance, and controlled administrative workflows.
- `Contracts/` contains interfaces for swappable behavior such as mail ingestion adapters, parsers, scanners, storage, and rate limiters.
- `DTOs/` contains immutable data carriers used between controllers, actions, services, jobs, and API layers.
- `Enums/` contains stable option sets and state machines shared across modules.
- `Events/` contains application-level events that cross feature boundaries.
- `Exceptions/` contains custom exception types for domain, infrastructure, security, and API failure cases.
- `Features/` contains the primary domain modules organized by business capability.
- `Filament/` contains Filament v4 admin panel resources, pages, widgets, relation managers, and admin-only actions.
- `Helpers/` contains pure helper functions only when a framework service or class would be excessive.
- `Http/` contains controllers, middleware, form requests, resources, and API presentation concerns.
- `Infrastructure/` contains external service adapters, provider-specific integrations, storage adapters, queue helpers, and observability exporters.
- `Jobs/` contains cross-feature jobs or generic operational jobs.
- `Listeners/` contains cross-feature listeners responding to application-level events.
- `Mail/` contains outbound mail classes for platform notifications, not temporary inbox outbound sending.
- `Models/` contains Eloquent models when implementation begins, kept lean and focused on persistence relationships.
- `Notifications/` contains Laravel notifications for account, admin, operational, billing, and security messages.
- `Policies/` contains authorization policies for resources that span modules or are conventional Laravel resources.
- `Providers/` contains Laravel service providers and module registration providers.
- `Repositories/` contains persistence abstractions only where direct Eloquent usage is not sufficient.
- `Services/` contains reusable application services that coordinate domain operations without owning a single use case.
- `Support/` contains shared framework-agnostic support classes, utility objects, normalizers, guards, and formatters.
- `Traits/` contains narrow reusable behavior with strict usage rules.
- `ValueObjects/` contains immutable domain values such as email addresses, aliases, domain names, message fingerprints, and retention windows.

## 3. Domain Module Structure

Domain modules live under `app/Features`.

```text
app/Features/
├── Abuse/
├── Alias/
├── Api/
├── Audit/
├── Billing/
├── Domain/
├── Inbox/
├── Ingestion/
├── Message/
├── Parsing/
├── Retention/
└── Team/
```

Each feature module may use the following internal structure when justified:

```text
FeatureName/
├── Actions/
├── Contracts/
├── DTOs/
├── Enums/
├── Events/
├── Exceptions/
├── Jobs/
├── Listeners/
├── Policies/
├── Queries/
├── Repositories/
├── Services/
├── Support/
├── Tests/
└── ValueObjects/
```

### Why This Structure Exists

- `Actions/` holds concrete use cases for the module.
- `Contracts/` defines module-owned interfaces.
- `DTOs/` carries structured input and output for module operations.
- `Enums/` defines module-specific states and fixed option sets.
- `Events/` publishes facts from the module.
- `Exceptions/` defines module-specific failures.
- `Jobs/` handles asynchronous work owned by the module.
- `Listeners/` reacts to module or cross-module events.
- `Policies/` defines module authorization rules.
- `Queries/` contains optimized read queries, dashboards, filters, and reporting query objects.
- `Repositories/` exists only when persistence complexity justifies abstraction.
- `Services/` contains reusable domain services.
- `Support/` contains internal helper classes specific to the module.
- `Tests/` may contain module-local tests if the team chooses feature-adjacent testing.
- `ValueObjects/` contains immutable concepts owned by the module.

### Required Feature Modules

- `Abuse/` manages rate limits, blocklists, threat signals, quarantine, and abuse scoring.
- `Alias/` manages alias generation, validation, reservation, collision prevention, and blocked patterns.
- `Api/` manages API token behavior, scopes, quotas, versioning rules, and API-specific responses.
- `Audit/` manages security, admin, user, system, and compliance event recording.
- `Billing/` manages plans, entitlements, usage limits, and future subscription integration.
- `Domain/` manages inbound email domains, verification, availability, health, and routing metadata.
- `Inbox/` manages inbox lifecycle, ownership, access, expiration, renewal, and public read behavior.
- `Ingestion/` manages inbound payload acceptance, normalization, idempotency, and processing dispatch.
- `Message/` manages message metadata, body storage references, attachment metadata, deletion, and display preparation.
- `Parsing/` manages MIME parsing, HTML sanitization, header extraction, and unsafe content handling.
- `Retention/` manages expiration, deletion, anonymization, archival, and cleanup workflows.
- `Team/` manages users, teams, roles, permissions, shared inboxes, and ownership boundaries.

## 4. Service Layer Structure

Services may exist globally under `app/Services` or inside a feature module under `app/Features/{Feature}/Services`.

```text
app/Services/
├── Cache/
├── Metrics/
├── RateLimiting/
├── Security/
└── Storage/
```

### Service Rules

- Use feature services for business behavior specific to one domain module.
- Use global services only for reusable platform concerns.
- Services must be injected, not instantiated manually.
- Services must not depend on controllers or Filament resources.
- Services may coordinate repositories, actions, external adapters, events, and cache.
- Services must remain testable without HTTP context.

### Why Services Exist

Services provide reusable capabilities that are broader than one action but still belong to application behavior. They keep controllers thin and prevent business rules from spreading across jobs, commands, and admin resources.

## 5. Action Classes

Actions represent explicit use cases.

Recommended locations:

```text
app/Features/Inbox/Actions/
app/Features/Ingestion/Actions/
app/Features/Message/Actions/
app/Actions/
```

### Action Rules

- Each action should perform one business use case.
- Action names must be verb phrases.
- Actions may accept DTOs and return DTOs, Value Objects, models, or result objects.
- Actions may dispatch events.
- Actions may use services, policies, repositories, locks, and transactions.
- Actions must not read directly from raw HTTP request objects.
- Actions must not contain presentation logic.

### Why Actions Exist

Actions make business use cases explicit and reusable from web controllers, API controllers, Filament actions, commands, jobs, and tests.

## 6. DTO Structure

DTOs live globally or inside modules:

```text
app/DTOs/
app/Features/{Feature}/DTOs/
```

Recommended DTO categories:

- Request DTOs for validated input crossing into the application layer.
- Result DTOs for structured action or service outcomes.
- Payload DTOs for queue-safe serialized data.
- Integration DTOs for provider-specific inbound email payloads.
- Read DTOs for optimized API or UI projections.

### DTO Rules

- DTOs must be immutable where practical.
- DTOs must carry data, not business workflows.
- DTOs must not depend on HTTP request classes.
- DTOs must not perform database queries.
- DTOs should use Value Objects for meaningful domain values.

### Why DTOs Exist

DTOs provide stable boundaries between controllers, actions, services, jobs, and external adapters. They reduce accidental coupling to HTTP, Filament, provider payloads, and persistence models.

## 7. Contracts

Contracts live under:

```text
app/Contracts/
app/Features/{Feature}/Contracts/
```

Recommended contracts:

- Inbound mail provider contract.
- MIME parser contract.
- HTML sanitizer contract.
- Attachment scanner contract.
- Message body storage contract.
- Abuse scoring contract.
- Rate limiter contract.
- Audit writer contract.
- Metrics recorder contract.
- Domain health checker contract.
- Webhook dispatcher contract.

### Contract Rules

- Create contracts only for behavior that has multiple implementations, external dependencies, or meaningful test boundaries.
- Bind contracts to implementations in service providers.
- Keep interfaces small and focused.
- Do not create interfaces automatically for every class.

### Why Contracts Exist

Contracts protect the core application from provider lock-in and make high-risk infrastructure replaceable without rewriting business modules.

## 8. Repositories

Repositories are optional and must be justified.

Recommended locations:

```text
app/Repositories/
app/Features/{Feature}/Repositories/
```

### When Repositories Are Justified

- The query is complex and reused across multiple use cases.
- Persistence may move between MySQL, object storage, search, or archival storage.
- The module needs optimized read/write separation.
- The code must hide partitioning, sharding, or multi-tenant lookup rules.
- Tests need to replace a persistence boundary for high-level behavior.

### When Repositories Are Not Justified

- Simple Eloquent CRUD.
- One-off queries.
- Thin wrappers that mirror model methods.
- Abstractions created only for ceremony.

### Why Repositories Exist

Repositories isolate complex persistence concerns such as high-volume message retrieval, inbox lookup by alias and domain, retention cleanup batches, and audit trail querying.

## 9. Policies

Policies live under:

```text
app/Policies/
app/Features/{Feature}/Policies/
```

### Policy Responsibilities

- Authorize user access to inboxes, messages, aliases, domains, teams, API tokens, and admin operations.
- Separate anonymous, registered, team, support, administrator, and super administrator capabilities.
- Protect sensitive message body access.
- Protect destructive actions.
- Protect admin-only operations.

### Policy Rules

- All user-visible resource access must pass through authorization.
- Policies must be explicit about ownership and team membership.
- Support access to message bodies must be denied by default.
- Admin bypasses must be deliberate, documented, and audited.

### Why Policies Exist

Policies centralize authorization and prevent access rules from being duplicated across web controllers, API controllers, Filament resources, jobs, and commands.

## 10. Events

Events live under:

```text
app/Events/
app/Features/{Feature}/Events/
```

Recommended event examples by concept:

- Inbox created.
- Inbox expired.
- Alias reserved.
- Message received.
- Message parsed.
- Message quarantined.
- Abuse signal recorded.
- Domain health changed.
- API quota exceeded.
- Admin sensitive action performed.

### Event Rules

- Events must describe facts that already happened.
- Event names should be past tense.
- Events must not decide what happens next.
- Events must carry only necessary context.
- Events must avoid carrying sensitive raw message content unless absolutely required.

### Why Events Exist

Events decouple modules and allow auditing, notifications, metrics, abuse analysis, and asynchronous workflows to evolve without changing the original use case.

## 11. Listeners

Listeners live under:

```text
app/Listeners/
app/Features/{Feature}/Listeners/
```

### Listener Responsibilities

- Record audit entries.
- Emit metrics.
- Dispatch follow-up jobs.
- Update read models or caches.
- Notify administrators.
- Record abuse signals.
- Trigger cleanup workflows.

### Listener Rules

- Listeners must be small and focused.
- Listeners handling slow work must dispatch jobs.
- Listeners must be idempotent where event replay or duplicate handling is possible.
- Listeners must not hide critical primary workflow behavior that must happen transactionally.

### Why Listeners Exist

Listeners keep secondary reactions separate from primary business actions, making the system easier to extend and safer to operate under high throughput.

## 12. Jobs

Jobs live under:

```text
app/Jobs/
app/Features/{Feature}/Jobs/
```

Recommended job categories:

- Mail ingestion dispatch.
- MIME parsing.
- Message body storage.
- Attachment scanning.
- Abuse analysis.
- Inbox expiration.
- Message retention cleanup.
- Domain health checks.
- Metrics aggregation.
- Webhook delivery.
- Export generation.

### Job Rules

- Jobs must be idempotent or explicitly duplicate-safe.
- Jobs must define queue, timeout, retry, and backoff strategy during implementation.
- Jobs must not rely on request state.
- Jobs must accept scalar IDs, DTOs, or queue-safe payloads.
- Jobs must re-fetch current state before mutating important records.
- Jobs must not process unbounded record sets.
- Jobs must emit useful failure context without leaking sensitive content.

### Why Jobs Exist

Jobs allow email ingestion, parsing, cleanup, scanning, analytics, and notifications to scale independently from HTTP traffic.

## 13. Mail Layer

Mail classes live under:

```text
app/Mail/
resources/views/mail/
```

### Mail Layer Scope

The mail layer is for platform-originated messages only, such as:

- Account verification.
- Password reset.
- Billing notices.
- Security alerts.
- Admin operational alerts.
- Team invitations.

It is not for sending email from temporary inboxes in the initial release.

### Mail Layer Rules

- Mail content must not include temporary inbox message bodies.
- Templates must use clear product language.
- Sensitive operational alerts should link to admin screens instead of embedding private data.
- Mailables must be thin presentation objects.

### Why The Mail Layer Exists

The platform still needs transactional product email while keeping temporary inbox outbound sending outside the initial scope.

## 14. Notifications

Notifications live under:

```text
app/Notifications/
app/Features/{Feature}/Notifications/
```

### Notification Channels

- Mail.
- Database.
- Broadcast.
- Slack or incident channel in future infrastructure.
- Webhook in future API expansion.

### Notification Rules

- Use notifications for user, team, admin, and operational alerts.
- Do not send sensitive message content through notifications.
- Respect user and team notification preferences when implemented.
- Operational notifications must be rate-limited to avoid alert storms.

### Why Notifications Exist

Notifications provide a consistent way to communicate account events, security issues, admin alerts, quota warnings, and operational incidents.

## 15. Helpers

Helpers live under:

```text
app/Helpers/
app/Support/
```

### Helper Rules

- Prefer services, Value Objects, or framework utilities over global helpers.
- Helpers must be pure and side-effect free.
- Helpers must not query the database.
- Helpers must not access request, session, auth, cache, queue, or filesystem state.
- Helpers must not become a dumping ground for business logic.

### Why Helpers Exist

Helpers are reserved for small, stable, reusable operations that do not justify a full service or Value Object.

## 16. Traits Usage Policy

Traits live under:

```text
app/Traits/
app/Features/{Feature}/Traits/
```

### Acceptable Trait Usage

- Shared Eloquent scopes with narrow purpose.
- Shared test helpers.
- Shared Filament table or form snippets when stable.
- Small reusable behavior that has no hidden dependencies.

### Prohibited Trait Usage

- Business workflows.
- Authorization logic.
- External service calls.
- Query logic that hides important performance behavior.
- State mutations with side effects.
- Large reusable blocks used to avoid proper composition.

### Why Traits Exist

Traits can reduce repetition in narrow cases, but overuse makes dependencies invisible. Composition through services and actions is preferred.

## 17. Enum Organization

Enums live under:

```text
app/Enums/
app/Features/{Feature}/Enums/
```

Recommended enums:

- Inbox status.
- Message status.
- Domain status.
- Alias type.
- Abuse severity.
- Quarantine reason.
- Retention action.
- API token scope.
- Plan interval.
- Audit event type.
- Admin role.

### Enum Rules

- Use enums for stable state machines and controlled option sets.
- Keep display labels out of enum business definitions unless the project standard allows localized label methods.
- Avoid enums for values expected to change frequently by database configuration.
- State transitions must be enforced by actions or services, not by enum names alone.

### Why Enums Exist

Enums make lifecycle states and fixed options explicit, safer, and easier to validate across web, API, jobs, and admin workflows.

## 18. Exceptions

Exceptions live under:

```text
app/Exceptions/
app/Features/{Feature}/Exceptions/
```

Recommended exception categories:

- Domain exceptions.
- Authorization exceptions.
- Validation boundary exceptions.
- Ingestion exceptions.
- Parser exceptions.
- Provider exceptions.
- Rate limit exceptions.
- Retention exceptions.
- Security exceptions.
- External integration exceptions.

### Exception Rules

- Exceptions must describe meaningful failure cases.
- Exceptions must not leak sensitive data.
- API-facing exceptions must map to consistent error responses.
- Retryable infrastructure exceptions must be distinguishable from permanent domain failures.
- Expected business denials should use result objects or validation where clearer than exceptions.

### Why Exceptions Exist

Exceptions provide clear failure boundaries for jobs, APIs, integrations, and domain workflows while supporting safe logging and consistent user-facing errors.

## 19. Config Files

Configuration lives under:

```text
config/
├── abuse.php
├── api.php
├── audit.php
├── billing.php
├── domains.php
├── ingestion.php
├── mail.php
├── queue.php
├── retention.php
├── security.php
└── services.php
```

### Config Responsibilities

- `abuse.php` defines rate limits, blocklist behavior, abuse scoring thresholds, and quarantine defaults.
- `api.php` defines API versioning, token behavior, scopes, quota defaults, and response policies.
- `audit.php` defines audited event types, retention, redaction, and sensitive admin action rules.
- `billing.php` defines plan defaults, entitlement keys, limits, and future provider settings.
- `domains.php` defines domain availability defaults, verification rules, and routing metadata defaults.
- `ingestion.php` defines inbound provider settings, payload limits, idempotency windows, and parsing dispatch options.
- `mail.php` retains Laravel mail configuration for platform-originated email.
- `queue.php` defines queue connections, queue names, retry behavior, and worker separation.
- `retention.php` defines inbox and message expiration policies, cleanup batch sizes, and archival rules.
- `security.php` defines sanitization rules, attachment rules, staff access rules, and remote content policy.
- `services.php` defines third-party service credentials and provider endpoints.

### Config Rules

- Environment variables must be read through config files, not scattered through application logic.
- Config files must not contain secrets.
- Config defaults must be safe for local development and explicit for production.
- High-risk limits must be configurable without code changes.

### Why Config Files Exist

Configuration separates deploy-time behavior from application logic and allows operations teams to tune ingestion, abuse, retention, queues, and security without rewriting code.

## 20. Testing Structure

```text
tests/
├── Architecture/
├── Feature/
│   ├── Abuse/
│   ├── Alias/
│   ├── Api/
│   ├── Audit/
│   ├── Billing/
│   ├── Domain/
│   ├── Inbox/
│   ├── Ingestion/
│   ├── Message/
│   ├── Parsing/
│   ├── Retention/
│   └── Team/
├── Integration/
├── Performance/
├── Security/
├── Support/
└── Unit/
```

### Why Each Test Folder Exists

- `Architecture/` verifies architectural rules such as controllers staying thin, modules not depending on forbidden layers, and naming conventions.
- `Feature/` verifies user-facing, API-facing, admin-facing, and module-level behavior through Laravel feature tests.
- `Integration/` verifies external adapters, mail ingestion providers, storage providers, Redis behavior, queues, and third-party services.
- `Performance/` stores load-sensitive tests, query count checks, and batch-processing expectations.
- `Security/` verifies authorization, sanitization, rate limits, token handling, staff access restrictions, and unsafe email rendering protections.
- `Support/` contains reusable test fixtures, builders, fake providers, and helper assertions.
- `Unit/` verifies Actions, Services, DTOs, Value Objects, Enums, and pure domain behavior.

### Testing Rules

- Critical inbox, ingestion, parsing, retention, abuse, and authorization workflows must be tested before production release.
- Queue jobs must be tested for retry safety and duplicate handling.
- API tests must verify versioning, error formats, scopes, and rate-limit behavior.
- Admin tests must verify permissions and destructive action protection.
- Security tests must verify sanitized HTML and blocked unsafe content.
- Performance-sensitive tests must verify bounded queries and batch sizes.

## Final Development Boundary

This architecture document defines where future files should live and why. It does not authorize creating application code, migrations, models, or infrastructure files without a specific implementation task.

