# Temail architecture

This is a contract-driven overview of the implemented system. Endpoint details belong in [`API_REFERENCE.md`](API_REFERENCE.md), protocol conventions in [`API_CONVENTION.md`](API_CONVENTION.md), local setup in [`LOCAL_DEVELOPMENT.md`](LOCAL_DEVELOPMENT.md), and production operations in [`PRODUCTION_RUNBOOK.md`](PRODUCTION_RUNBOOK.md).

## System boundaries

Temail exposes an owner API, a signed inbound webhook, and an authorized Filament admin surface. Database persistence, queue processing, private attachment storage, cache/locks, and optional scanner infrastructure are separate runtime dependencies.

High-level inbound flow:

```text
Inbound provider
  -> signed webhook
  -> queued processing
  -> inbox resolution
  -> email persistence
  -> attachment scanning
  -> owner API
```

Native SMTP/LMTP ingress, public billing, and public API-key issuance are outside the system boundary.

## Stack

The application uses Laravel 12 on PHP 8.2+, Eloquent/database persistence, Laravel queues and scheduling, Filament 5, Pest/PHPUnit tests, and configurable database or Redis queue/cache infrastructure.

## Application layering

- **Actions** perform focused state-changing operations and transactional decisions.
- **Services** coordinate domain workflows, policies, health reports, retention, metrics, and scanner orchestration.
- **Repositories** encapsulate persistence queries and ownership-aware lookups where present.
- **DTOs** normalize validated input and typed service data.
- **Policies/middleware** enforce API-key scopes, operator capability, owner visibility, and admin access.
- **Jobs** execute asynchronous work with bounded retries and idempotent state transitions.
- **Commands** expose safe health, retention, expiration, and operational controls.

## Domain modules

The main modules are API keys and scopes, platform MailServers, owner inboxes and emails, attachments, signed inbound routing, retention/expiration, operational metrics, process readiness, audit/request logs, and Filament administration.

## API-key authentication and scopes

Protected `/api/v1` routes resolve a bearer API key, reject invalid/revoked/expired keys and unavailable owners, then apply scope middleware and rate limits. MailServer scopes are operator/admin-gated; inbox read/write scopes govern owner resources. Public API-key issuance is not an API boundary.

## Owner isolation

Inbox, email, and attachment queries are constrained by the authenticated owner and lifecycle visibility rules. Foreign, expired, inactive, deleted, or otherwise inaccessible resources fail closed as not found. Filament permissions are a separate admin boundary and do not bypass API ownership rules.

## MailServer selection and capacity

MailServers are platform-managed global infrastructure. Inbox assignment uses configured pool and entitlement rules, availability, and capacity constraints. Pool entitlements do not grant API scope or operator capability, and request input cannot select an unauthorized infrastructure record.

## Entitlements and quota evaluation

Quota and feature entitlement services evaluate limits such as inbox capacity, API-key capacity, and MailServer pools before state-changing actions. Missing or disabled capabilities fail closed where required; nullable limits are interpreted only according to the owning entitlement contract.

## Signed inbound webhook pipeline

`POST /api/v1/inbound/webhook` validates provider identity, timestamp skew, HMAC signature, message ID, bounded content type/body, and recipient data. It queues an envelope for asynchronous resolution and persistence without exposing raw inbound content in responses or metrics.

## Email and attachment persistence

Resolved envelopes create or update owned email state through the inbound processing pipeline. Attachments are persisted in private storage with bounded count/size and a scan status. Owner API downloads require the correct inbox/email/attachment relationship and safe range/download preconditions.

## Attachment scanning lifecycle

The scanner backend defaults to disabled. When enabled, attachments move through pending/scanning to clean, infected, or failed. Unavailable/transient scanner outcomes remain retryable; infected and permanent validation outcomes are terminal. A failed or disabled scanner is never equivalent to clean.

## Failure and replay boundary

Inbound failures and attachment-scan retry exhaustion are recorded as safe operational failures. Administrative replay is eligibility- and authorization-controlled, idempotent, and audited. Raw MIME replay and arbitrary public replay endpoints are not exposed.

## Retention and expiration

Retention services apply configured, bounded cleanup with legal-hold checks. Optional inbox expiration is a separate scheduled lifecycle that deactivates eligible expired inboxes and preserves child records according to policy. Retention does not bypass ownership, audit, or hold rules.

## Metrics and health boundaries

Inbound metrics aggregate safe lifecycle counters, latency, backlog, and retry signals without message content or unbounded attachment identity. `inbound:health`, `processes:health`, and `attachments:scanner-health` report separate concerns and fail closed when their dependencies are unavailable.

## Queue and scheduler dependencies

Inbound processing and scan jobs require a functioning queue and supervised workers. Laravel scheduling drives heartbeats and optional maintenance tasks; exactly one scheduler strategy must run. Worker and scheduler heartbeats feed process readiness but do not claim workload success by themselves.

## Relational concurrency guarantees

Relational tests exercise row locks, uniqueness, owner quotas, and concurrent capacity decisions using the repository harness. The system relies on transactional/conditional persistence for those guarantees. SQLite-only execution is not proof of production relational concurrency behavior.

## Filament operator boundary

Filament is an authenticated admin/operations surface for approved users. Resources and pages enforce platform/admin authorization and safe escaped output. Filament access does not grant public API scopes, owner visibility, or permission to bypass audit and retention controls.

## Deferred boundaries

- Native SMTP/LMTP ingress is not implemented; the signed webhook is the inbound boundary.
- Public customer billing/subscription APIs are not implemented.
- Public API-key issuance or management is not exposed.
- Complete production readiness depends on deployment-managed database, queue, cache/lock, storage, proxy, and optional scanner services.

The architecture therefore describes bounded behavior and dependencies, not an exactly-once delivery guarantee or an always-enabled scanner.
