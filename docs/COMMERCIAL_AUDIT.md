# Commercial Audit

Audit date: 2026-07-28. Scope: Prompt 635 final audit of the commercial subsystem.

## Baseline

- Branch: `feature/commercial-plans`
- Starting HEAD: `b4afcf2` (`test: complete commercial usage coverage`)
- Required recent commits are present: `b4afcf2`, `ec98c26`, `00808da`, and `4673ca4`.
- The tree was not clean at audit start. Modified/untracked files outside this prompt were preserved and were not changed.

## Architecture and consistency

`EntitlementService` provides the effective entitlement source of truth. `CommercialQuotaResolver` centralizes quota limits, usage, remaining capacity, and reset information. `CommercialUsageSummaryService` and `CommercialThresholdNotificationService` consume that resolver. `CommercialResponseFactory` centralizes the API envelopes for feature denial, plan limits, rate limits, and mapped domain exceptions. Subscription transitions are centralized in `SubscriptionLifecycleService`.

Inbox, API key, and webhook inventory enforcement call the quota resolver. API controllers and middleware use the response factory; outbound exception mapping delegates there through `CommercialApiErrorMapper`. The review found no controller-owned entitlement resolution and no direct premium-plan authorization check. The configured recommended-plan default is presentation metadata, not an authorization rule.

## Security

API-key scope validation, rotation/revocation and ownership checks are implemented. Webhooks enforce HTTPS and SSRF validation, encrypted secrets, HMAC verification, retry/delivery state, and entitlement rechecks. Inbox creation invokes quota and alias entitlement checks. Outbound has ownership/sender authorization, suppression, dispatch-time authorization recheck, usage reservation, and rollout controls. These paths are designed fail-closed when an entitlement is absent or malformed.

## Database and queues

The reviewed subscription lifecycle uses database transactions and `lockForUpdate`; it prevents overlapping active/trial grants at the service layer. Webhook delivery and outbound dispatch are scheduled with `afterCommit`; delivery state provides idempotent retry behavior. Queue topology and health are exposed through outbound readiness/operations services.

The relational migration and constraint audit was source-level only in this session; MySQL/PostgreSQL/SQLite migration validation remains a release gate because relational CI was not run.

## Dead-code and documentation review

Commercial documentation reviewed: `COMMERCIAL_USAGE_SUMMARY`, `API_COMMERCIAL_ENTITLEMENT`, `USER_WEBHOOKS`, `SUBSCRIPTION_LIFECYCLE_CONTRACT`, and `CENTRAL_ENTITLEMENT_RESOLUTION`. The requested dead-code search found compatibility references (`legacy`), documented error codes, and test fixtures; no obsolete helper was removed without a demonstrated safe replacement. No unrelated refactor was made.

## Testing and static analysis

The requested eight-filter regression command was started, but the process exceeded the 120-second execution limit without reporting individual suite results. It is therefore **not passed**. `vendor/bin/pint --test` was run and failed across pre-existing application and test files. PHPStan was intentionally skipped: this audit adds documentation only, with no changed PHP path to analyse. `git diff --check` passed. Pint was not allowed to alter a dirty user worktree.

## Release recommendation

**Do not declare production-ready yet.** Clear the clean-tree, regression, relational CI, formatter, static-analysis, and diff gates above. Once they pass on a dedicated release commit, the reviewed commercial architecture is suitable for release consideration.
