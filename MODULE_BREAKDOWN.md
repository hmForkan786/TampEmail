# MODULE BREAKDOWN

## Purpose

This document breaks the Temporary Email SaaS into focused product and platform modules. Each module has a clear responsibility, known dependencies, public interface, and future expansion path.

No application code, migrations, or models are defined here.

## Module Design Rules

- Each module owns one business capability.
- Modules communicate through Actions, DTOs, Contracts, Events, and Jobs.
- Modules must not reach into another module's internal implementation.
- Controllers, Filament resources, API endpoints, and jobs should call public module interfaces.
- Shared technical concerns belong in `Support`, `Infrastructure`, or clearly named platform services.
- Cross-module workflows should be event-driven where consistency does not need to be immediate.

## 1. User Module

### Responsibility

- Manage user accounts.
- Manage authentication identity boundaries.
- Manage user profile data.
- Support anonymous, registered, team member, support operator, administrator, and super administrator concepts.
- Provide ownership context for inboxes, API tokens, subscriptions, billing, and notifications.

### Dependency

- Subscription Module for plan and entitlement checks.
- Billing Module for customer and payment identity.
- Notification Module for account and security messages.
- Audit Module for sensitive user and admin actions.
- Settings Module for account-level preferences.

### Public Interface

- Register user.
- Resolve authenticated user.
- Update profile.
- Change account status.
- Assign role.
- Check user capability.
- Deactivate or delete account according to retention policy.

### Future Expansion

- Team workspaces.
- Enterprise SSO.
- Multi-factor authentication.
- User impersonation with strict audit controls.
- Account export and privacy compliance workflows.

## 2. Inbox Module

### Responsibility

- Create temporary inboxes.
- Track inbox lifecycle.
- Resolve inbox ownership.
- Enforce inbox expiration.
- Support anonymous and authenticated inbox access.
- Provide inbox read views for web and API consumers.

### Dependency

- User Module for ownership.
- Domain Module for available domains.
- Email Module for received messages.
- Subscription Module for inbox limits and retention windows.
- Audit Module for sensitive inbox actions.
- Analytics Module for usage metrics.

### Public Interface

- Create inbox.
- Renew inbox.
- Expire inbox.
- Delete inbox.
- Resolve inbox by address.
- List inbox messages.
- Check inbox access.

### Future Expansion

- Shared inboxes.
- Private inbox links.
- Reserved inbox names.
- Inbox folders or labels.
- WebSocket-based live inbox updates.

## 3. Email Module

### Responsibility

- Represent inbound email messages.
- Store email metadata.
- Manage sanitized email body access.
- Track message processing status.
- Support message deletion and retention behavior.
- Provide display-ready message data.

### Dependency

- Inbox Module for message ownership.
- Domain Module for recipient resolution.
- Attachment Module for attachment metadata and scanning.
- Audit Module for sensitive message reads.
- Analytics Module for message volume metrics.
- Settings Module for retention and display behavior.

### Public Interface

- Store inbound message.
- List messages for inbox.
- Fetch message details.
- Mark message as processed.
- Mark message as quarantined.
- Delete message.
- Prepare message for safe display.

### Future Expansion

- Full-text search.
- Message tagging.
- Message export.
- Object storage for large message bodies.
- Dedicated search indexing service.

## 4. Domain Module

### Responsibility

- Manage temporary email domains.
- Track domain verification and health.
- Control domain availability.
- Provide routing metadata for inbound email processing.
- Enforce domain-specific rules and restrictions.

### Dependency

- Inbox Module for address generation.
- Email Module for recipient resolution.
- Settings Module for platform domain policies.
- Analytics Module for domain health and volume reporting.
- Audit Module for administrative domain changes.

### Public Interface

- Add domain.
- Disable domain.
- Verify domain.
- Check domain health.
- Resolve active domain.
- List public domains.
- Enforce domain usage policy.

### Future Expansion

- Customer-owned custom domains.
- DNS automation.
- Multi-region domain routing.
- Provider failover.
- Domain reputation monitoring.

## 5. Subscription Module

### Responsibility

- Represent plans and entitlements.
- Enforce product limits.
- Define plan-based access to features.
- Track usage eligibility for users and teams.

### Dependency

- User Module for account ownership.
- Billing Module for payment and invoice state.
- Inbox Module for inbox count and retention limits.
- API Module for API quotas.
- Settings Module for default plan configuration.

### Public Interface

- Get current plan.
- Check entitlement.
- Check usage limit.
- Reserve usage capacity.
- Release usage capacity.
- Change plan state.

### Future Expansion

- Team plans.
- Usage-based pricing.
- Promotional plans.
- Trial management.
- Enterprise custom entitlements.

## 6. Billing Module

### Responsibility

- Manage billing customers.
- Track payment provider references.
- Prepare subscription billing workflows.
- Track invoices, payment state, and billing events when implemented.

### Dependency

- User Module for customer ownership.
- Subscription Module for plan state.
- Notification Module for billing notices.
- Audit Module for payment-related administrative actions.
- Settings Module for billing provider configuration.

### Public Interface

- Create billing customer.
- Sync billing status.
- Start checkout.
- Handle billing webhook.
- Mark payment failure.
- Record invoice event.

### Future Expansion

- Stripe or Paddle integration.
- Tax handling.
- Coupon support.
- Invoice exports.
- Enterprise invoicing.

## 7. Notification Module

### Responsibility

- Send platform notifications.
- Manage user, admin, operational, and security notification flows.
- Support mail, database, broadcast, and future webhook channels.
- Prevent notification storms.

### Dependency

- User Module for recipients.
- Settings Module for preferences.
- Billing Module for billing alerts.
- Audit Module for sensitive notification events.
- Analytics Module for delivery metrics.

### Public Interface

- Notify user.
- Notify team.
- Notify administrator.
- Notify support operator.
- Send operational alert.
- Record notification delivery state.

### Future Expansion

- User notification preferences.
- Webhook notifications.
- Slack or Teams incident alerts.
- Digest emails.
- Notification templates managed from admin.

## 8. Attachment Module

### Responsibility

- Track inbound email attachments.
- Enforce attachment limits.
- Scan or quarantine unsafe files.
- Manage attachment metadata and storage references.
- Control attachment download permissions.

### Dependency

- Email Module for message ownership.
- Inbox Module for access checks.
- Settings Module for file size and file type rules.
- Audit Module for attachment access.
- Analytics Module for storage and risk metrics.

### Public Interface

- Register attachment.
- Scan attachment.
- Quarantine attachment.
- Resolve attachment metadata.
- Authorize attachment download.
- Delete attachment.

### Future Expansion

- Virus scanning provider integration.
- Object storage lifecycle policies.
- Preview generation.
- Attachment sandboxing.
- Paid-plan attachment retention.

## 9. API Module

### Responsibility

- Provide versioned public API access.
- Manage API tokens and scopes.
- Enforce API quotas.
- Format API responses and errors consistently.
- Track API usage separately from web usage.

### Dependency

- User Module for token ownership.
- Subscription Module for API entitlement and quotas.
- Inbox Module for inbox operations.
- Email Module for message operations.
- Audit Module for sensitive API actions.
- Analytics Module for API usage reporting.

### Public Interface

- Issue API token.
- Revoke API token.
- Validate API scope.
- Check API quota.
- Create inbox through API.
- List messages through API.
- Fetch message through API.

### Future Expansion

- Webhooks.
- OAuth clients.
- API key rotation policies.
- Developer dashboard.
- SDKs and OpenAPI documentation.

## 10. Admin Module

### Responsibility

- Provide Filament v4 administration.
- Manage users, domains, inboxes, messages, plans, settings, abuse controls, billing records, and operational status.
- Expose dashboards for health, volume, queues, abuse, and storage.
- Protect destructive and sensitive administrative actions.

### Dependency

- All business modules for managed resources.
- Audit Module for admin activity records.
- Analytics Module for dashboards.
- Settings Module for platform configuration.
- Notification Module for operational alerts.

### Public Interface

- Filament resources.
- Filament pages.
- Filament widgets.
- Admin actions.
- Admin dashboards.
- Admin-only exports.

### Future Expansion

- Role-based admin panel sections.
- Support operator workflows.
- Incident response console.
- Admin approval workflows.
- Compliance exports.

## 11. Analytics Module

### Responsibility

- Collect operational and product metrics.
- Report inbox creation volume, inbound email volume, API usage, queue health, abuse trends, domain health, and storage growth.
- Provide dashboard-ready aggregates.
- Support observability without leaking sensitive message content.

### Dependency

- Inbox Module for inbox metrics.
- Email Module for message metrics.
- Domain Module for domain metrics.
- API Module for API usage metrics.
- Billing and Subscription Modules for plan analytics.
- Audit Module for security-sensitive activity.

### Public Interface

- Record metric.
- Aggregate usage.
- Fetch dashboard statistics.
- Fetch time-series data.
- Export operational metrics.

### Future Expansion

- Data warehouse pipeline.
- Cohort analytics.
- Revenue analytics.
- Abuse prediction.
- Multi-region operational reporting.

## 12. Advertisement Module

### Responsibility

- Manage ad placements for public or free-plan experiences.
- Control advertisement visibility by plan, region, page, and policy.
- Track impression and click metadata without collecting unnecessary personal data.

### Dependency

- User Module for account and plan context.
- Subscription Module for ad-free entitlement checks.
- Settings Module for ad configuration.
- Analytics Module for ad performance metrics.
- Audit Module for administrative ad changes.

### Public Interface

- Resolve active ad placement.
- Check ad eligibility.
- Record impression.
- Record click.
- Disable ad campaign.

### Future Expansion

- Sponsored inbox pages.
- Internal promotional banners.
- Ad provider integration.
- Geo-based ad rules.
- A/B testing.

## 13. Settings Module

### Responsibility

- Manage platform-wide configuration stored in the application or database.
- Provide settings for retention, abuse thresholds, domains, API limits, security, billing, notifications, and ads.
- Separate environment configuration from admin-managed product settings.

### Dependency

- Admin Module for settings management UI.
- Audit Module for settings change records.
- All modules that consume runtime-configurable behavior.

### Public Interface

- Read setting.
- Update setting.
- Validate setting group.
- Cache settings.
- Clear settings cache.
- Audit setting change.

### Future Expansion

- Per-team settings.
- Per-domain settings.
- Feature flags.
- Configuration versioning.
- Approval workflow for high-risk settings.

## 14. Audit Module

### Responsibility

- Record security, administrative, user, API, billing, and system events.
- Support investigation and compliance.
- Track sensitive reads and writes.
- Redact private data from audit payloads.

### Dependency

- User Module for actor identity.
- Admin Module for administrative actions.
- API Module for token-based actions.
- Settings Module for audit retention policy.
- Analytics Module for security reporting.

### Public Interface

- Record audit event.
- Query audit trail.
- Redact sensitive payload.
- Export audit records.
- Enforce audit retention.

### Future Expansion

- Tamper-resistant audit storage.
- Compliance exports.
- Security incident timelines.
- External SIEM integration.
- Per-tenant audit logs.

## Cross-Module Communication

### Synchronous Calls

Use synchronous calls when the caller needs an immediate decision:

- Authorization checks.
- Entitlement checks.
- Inbox resolution.
- Domain availability checks.
- Rate-limit decisions.

### Asynchronous Events

Use events and listeners when work can happen after the primary action:

- Audit recording.
- Analytics metrics.
- Notification dispatch.
- Abuse analysis.
- Cleanup scheduling.
- Webhook delivery.

### Queue Jobs

Use jobs when work is expensive, retryable, or failure-prone:

- Email parsing.
- Attachment scanning.
- Message storage.
- Retention cleanup.
- Domain health checks.
- Analytics aggregation.

## Module Boundary Rules

- A module may expose Actions, Services, DTOs, Contracts, Events, and Query objects as its public interface.
- A module must not depend on another module's private Support classes.
- A module must not directly mutate another module's internal state except through public interfaces.
- Cross-module database joins must be justified by performance and documented.
- Filament resources must call module interfaces instead of embedding business workflows.
- API controllers must call module interfaces instead of duplicating web behavior.
- Jobs must re-check current state through module interfaces before making important changes.

## Initial Implementation Priority

Recommended module implementation order:

1. Settings Module
2. User Module
3. Domain Module
4. Inbox Module
5. Email Module
6. Attachment Module
7. Audit Module
8. API Module
9. Admin Module
10. Notification Module
11. Analytics Module
12. Subscription Module
13. Billing Module
14. Advertisement Module

This order supports the core temporary email workflow first, then adds monetization, analytics, and growth features.

