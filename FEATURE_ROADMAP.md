# FEATURE ROADMAP

## Purpose

This document breaks the Temporary Email SaaS into implementation-ready feature phases.

The roadmap is ordered so the product can be built from foundation to revenue and scale without skipping critical security, retention, and operational concerns.

No application code, migrations, or models are defined here.

## Roadmap Principles

- Build the core email workflow before monetization.
- Keep every feature small enough to test and release safely.
- Add observability and abuse protection early.
- Avoid implementing premium features before limits and ownership are clear.
- Avoid building analytics before the events worth measuring exist.
- Each feature must define acceptance criteria before implementation.

## Feature 1: Authentication

### Goal

Provide secure account access for users, administrators, support operators, and future team members.

### Scope

- User registration.
- User login.
- User logout.
- Password reset.
- Email verification.
- Account profile basics.
- Admin authentication through Filament.
- Role foundation for user, support, administrator, and super administrator.

### Key Requirements

- Public anonymous inbox usage must still be possible if enabled.
- Admin access must be protected separately from public flows.
- Sensitive account actions must be audited.
- Authentication must follow Laravel 12 best practices.

### Dependencies

- User Module.
- Audit Module.
- Notification Module.
- Settings Module.

### Acceptance Criteria

- A user can register and log in.
- A user can reset password.
- Admin users can access Filament.
- Non-admin users cannot access Filament.
- Authentication events are auditable.

### Future Expansion

- Multi-factor authentication.
- Team invitations.
- SSO.
- Device/session management.

## Feature 2: Domains

### Goal

Allow administrators to manage inbound temporary email domains.

### Scope

- Add domain.
- Disable domain.
- Mark domain as public or private.
- Track domain verification state.
- Track domain health state.
- Configure domain availability for free or premium users.

### Key Requirements

- Domain names must be unique.
- Disabled domains must not be used for new inboxes.
- Existing inboxes must keep historical domain references.
- Domain changes must be audited.

### Dependencies

- Domain Module.
- Admin Module.
- Audit Module.
- Settings Module.

### Acceptance Criteria

- Admin can create and disable domains.
- Public inbox generation uses only active public domains.
- Domain status appears in admin.
- Domain changes produce audit records.

### Future Expansion

- Custom user domains.
- DNS verification automation.
- Domain reputation monitoring.
- Multi-provider domain routing.

## Feature 3: Inbox

### Goal

Allow users to create, access, renew, expire, and delete temporary inboxes.

### Scope

- Random inbox generation.
- Custom alias validation where allowed.
- Public inbox page.
- Authenticated inbox list.
- Inbox expiration.
- Inbox deletion.
- Inbox renewal if allowed.
- Basic inbox access control.

### Key Requirements

- Alias and domain combinations must be unique while active.
- Anonymous inboxes must have stricter limits.
- Inboxes must stop receiving accessible messages after expiration.
- Inbox creation must be rate-limited.

### Dependencies

- User Module.
- Domain Module.
- Inbox Module.
- Subscription Module.
- Audit Module.
- Settings Module.

### Acceptance Criteria

- Anonymous visitor can create an inbox if public creation is enabled.
- Registered user can create and view owned inboxes.
- Expired inbox cannot be used as active.
- Duplicate active aliases are prevented.
- Inbox creation is rate-limited.

### Future Expansion

- Reserved aliases.
- Shared inboxes.
- Private inbox links.
- Real-time inbox updates.

## Feature 4: Receive Email

### Goal

Receive inbound emails, process them asynchronously, and display safe message content in the correct inbox.

### Scope

- Inbound provider endpoint or ingestion adapter.
- Recipient resolution.
- Message idempotency.
- Queue dispatch for parsing and storage.
- MIME parsing.
- HTML sanitization.
- Message metadata storage.
- Message listing and detail view.

### Key Requirements

- Inbound email must be treated as untrusted input.
- Processing must be queue-based.
- Duplicate messages must be detected where possible.
- Unsafe HTML must never render directly.
- Large messages must not block HTTP requests.

### Dependencies

- Inbox Module.
- Email Module.
- Domain Module.
- Ingestion Module.
- Parsing Module.
- Abuse Module.
- Audit Module.
- Queue.
- Redis.

### Acceptance Criteria

- Inbound email for an active inbox is accepted.
- Message appears in the inbox after processing.
- Invalid or expired recipients are rejected, discarded, or quarantined according to policy.
- Message body is sanitized before display.
- Processing failures are logged and retryable.

### Future Expansion

- Multiple inbound email providers.
- Dedicated mail ingestion service.
- Webhook delivery for received messages.
- Search indexing.

## Feature 5: Attachments

### Goal

Support safe handling of inbound email attachments.

### Scope

- Attachment metadata extraction.
- Attachment size limits.
- Attachment type restrictions.
- Storage reference tracking.
- Attachment scanning status.
- Attachment download authorization.
- Quarantine unsafe attachments.

### Key Requirements

- Attachments must be optional and configurable.
- Unsafe or oversized attachments must be blocked or quarantined.
- Attachment downloads must require access authorization.
- Attachment files must follow retention cleanup.

### Dependencies

- Email Module.
- Attachment Module.
- Inbox Module.
- Settings Module.
- Audit Module.
- Storage.
- Queue.

### Acceptance Criteria

- Safe attachment metadata is visible on messages.
- Oversized attachments are rejected or quarantined.
- Unauthorized users cannot download attachments.
- Attachment access can be audited.
- Attachment cleanup follows retention policy.

### Future Expansion

- Virus scanning integration.
- Preview generation.
- Paid-plan attachment access.
- Object storage lifecycle automation.

## Feature 6: OTP

### Goal

Optimize the temporary inbox experience for one-time-password and verification-code emails.

### Scope

- Detect common OTP email patterns.
- Extract likely verification codes.
- Highlight OTP code in inbox UI.
- Provide copy action for detected code.
- Track OTP detection confidence.

### Key Requirements

- OTP extraction must not require unsafe HTML rendering.
- OTP extraction must support text and sanitized HTML content.
- False positives must not mutate message content.
- OTP detection must be optional and configurable.

### Dependencies

- Email Module.
- Parsing Module.
- Inbox Module.
- Settings Module.
- Analytics Module.

### Acceptance Criteria

- Common OTP messages show detected code.
- Users can copy detected OTP.
- Non-OTP messages do not show misleading code UI.
- OTP extraction works asynchronously after message parsing.

### Future Expansion

- Provider-specific OTP patterns.
- Machine-learning-assisted extraction.
- Browser extension integration.
- OTP auto-refresh UI.

## Feature 7: Premium

### Goal

Introduce paid plan capabilities and entitlement-based product limits.

### Scope

- Plan definitions.
- Entitlement checks.
- Longer retention.
- More inboxes.
- Reserved aliases.
- Ad-free experience.
- Higher API limits.
- Attachment access by plan.

### Key Requirements

- Premium behavior must be driven by entitlements, not hard-coded roles.
- Free and anonymous limits must remain enforceable.
- Plan changes must update access predictably.
- Premium features must degrade gracefully when subscription status changes.

### Dependencies

- Subscription Module.
- Billing Module.
- User Module.
- Inbox Module.
- API Module.
- Advertisement Module.
- Settings Module.

### Acceptance Criteria

- Plan limits can be checked consistently.
- Premium users receive configured retention and inbox limits.
- Free users are blocked from premium-only features.
- Premium access can be revoked when subscription becomes inactive.

### Future Expansion

- Team plans.
- Usage-based billing.
- Trials.
- Coupons.
- Enterprise entitlements.

## Feature 8: API

### Goal

Provide versioned API access for developers and automated systems.

### Scope

- API token creation and revocation.
- API scopes.
- Versioned endpoints.
- Inbox creation endpoint.
- Message listing endpoint.
- Message detail endpoint.
- Message deletion endpoint.
- API rate limits.
- Consistent JSON response and error format.

### Key Requirements

- API must expose public identifiers, not internal IDs.
- API must enforce scopes and quotas.
- API errors must follow the project error standard.
- API usage must be observable separately from web usage.

### Dependencies

- API Module.
- User Module.
- Subscription Module.
- Inbox Module.
- Email Module.
- Audit Module.
- Analytics Module.

### Acceptance Criteria

- User can create and revoke API tokens.
- API token can create inboxes if scoped and entitled.
- API token can list and read messages.
- Rate limits return standard error response.
- API responses follow JSON standards.

### Future Expansion

- Webhooks.
- OAuth clients.
- SDKs.
- OpenAPI documentation.
- API usage dashboard.

## Feature 9: Ads

### Goal

Support advertisement placement for anonymous and free-plan users.

### Scope

- Ad campaign records.
- Placement rules.
- Plan-based ad visibility.
- Impression tracking.
- Click tracking.
- Admin controls for enabling and disabling ads.

### Key Requirements

- Premium users must be eligible for ad-free experience.
- Ads must not break inbox usability.
- Tracking must avoid unnecessary personal data.
- Ad configuration must be controlled through admin settings.

### Dependencies

- Advertisement Module.
- User Module.
- Subscription Module.
- Settings Module.
- Analytics Module.
- Admin Module.

### Acceptance Criteria

- Ads can be shown on configured public surfaces.
- Premium users do not see ads when entitlement applies.
- Impressions and clicks are tracked.
- Admin can disable an ad campaign.

### Future Expansion

- Provider integrations.
- Sponsored placements.
- Internal promotions.
- A/B testing.
- Geo-based placement rules.

## Feature 10: Analytics

### Goal

Provide product, operational, abuse, API, billing, and growth analytics.

### Scope

- Inbox creation metrics.
- Inbound email metrics.
- API usage metrics.
- Domain health metrics.
- Queue health metrics.
- Abuse trend metrics.
- Retention cleanup metrics.
- Admin dashboard widgets.

### Key Requirements

- Analytics must not store raw email bodies.
- Metrics should be event-driven where possible.
- High-volume analytics must be aggregated.
- Analytics failures must not block email ingestion.

### Dependencies

- Analytics Module.
- Inbox Module.
- Email Module.
- Domain Module.
- API Module.
- Abuse Module.
- Admin Module.
- Queue.

### Acceptance Criteria

- Admin dashboard shows core usage metrics.
- Email ingestion volume is measurable.
- API usage is measurable.
- Queue backlog and failures are visible.
- Analytics jobs can be delayed without breaking product workflows.

### Future Expansion

- Data warehouse.
- Revenue analytics.
- Cohort analytics.
- Abuse prediction.
- Public status dashboard.

## Supporting Features

### Audit

Audit should be introduced early and expanded throughout the roadmap. Sensitive user actions, admin changes, API token events, inbox lifecycle events, and billing changes must be recorded.

### Settings

Settings should be implemented before features become configurable. Retention, abuse limits, domain behavior, API limits, security behavior, and ads should read from centralized settings.

### Abuse Protection

Abuse controls must evolve alongside inbox creation, email ingestion, API usage, attachments, and ads. Rate limits and blocklists are required before public launch.

### Retention

Retention must be implemented before the service handles meaningful traffic. Expired inboxes, messages, attachments, audit logs, analytics events, and billing records each need separate retention behavior.

## Suggested Release Phases

### Phase 1: Foundation

- Authentication.
- Settings.
- Admin access.
- Audit foundation.
- Domain management.

### Phase 2: Core Temporary Email

- Inbox creation.
- Inbox expiration.
- Receive email.
- Message viewing.
- Basic abuse controls.

### Phase 3: Safety and Retention

- Attachment handling.
- HTML sanitization hardening.
- Retention cleanup.
- Queue monitoring.
- Domain health checks.

### Phase 4: Developer Product

- API tokens.
- Versioned API.
- API quotas.
- API usage analytics.

### Phase 5: Monetization

- Premium plans.
- Billing integration.
- Ads for free users.
- Ad-free premium entitlement.

### Phase 6: Scale and Intelligence

- Advanced analytics.
- OTP extraction.
- Webhooks.
- Search.
- Data warehouse or rollups.

## Roadmap Completion Rule

A feature is not complete until it has:

- Defined authorization rules.
- Defined validation rules.
- Defined rate limits where public or API-facing.
- Defined queue behavior where asynchronous.
- Defined observability.
- Defined retention behavior.
- Defined admin controls where operationally necessary.
- Defined tests for success, failure, and authorization paths.

