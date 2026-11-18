# Commercial Audit

Audit date: 2026-07-28. Scope: Prompt 635 final audit of the commercial subsystem (Prompts 626–634).

## Baseline

- Branch: `feature/commercial-plans`
- Required recent commits present: `4673ca4`, `00808da`, `ec98c26`, `b4afcf2` (plus prior docs commit on the branch).
- Unrelated dirty/untracked Filament/UI files were preserved and excluded from Prompt 635 commits.

## Architecture

| Layer | Authority | Result |
| --- | --- | --- |
| Entitlement resolution | `EntitlementService` | PASS |
| Quota calculation | `CommercialQuotaResolver` | PASS |
| Usage summary | `CommercialUsageSummaryService` → quota resolver | PASS |
| Threshold alerts | `CommercialThresholdNotificationService` → quota + audit | PASS |
| API denial envelopes | `CommercialResponseFactory` (+ `CommercialApiErrorMapper` compat) | PASS |
| Plan admin mutations | `CommercialPlanManagementService` | PASS |
| Subscription transitions | `SubscriptionLifecycleService` | PASS |

No controller-owned entitlement resolution, no hardcoded Premium authorization gates, no duplicated quota calculators, and no duplicate response builders were found. Feature keys resolve through the seeded catalogue / `config/commercial.php` summary map. `recommended_plan_slug` is presentation metadata only.

## Security

| Surface | Controls verified | Result |
| --- | --- | --- |
| API | Scopes, rotation, revoke, commercial rate limit, ownership, entitlement middleware | PASS |
| Webhooks | HTTPS-only, SSRF blocks, encrypted secrets, HMAC, retries, entitlement recheck | PASS |
| Inbox | Quota, ownership, custom-alias restriction, fail-closed denials | PASS |
| Outbound | Dispatch recheck, usage reservation, sender ownership, suppression, rollout | PASS |

Fail-closed behaviour is the default for missing/malformed entitlements and zero limits.

## Consistency

Inbox, Outbound, API, and Webhooks map commercial denials through `CommercialResponseFactory` (feature unavailable, plan limit, rate limit, upgrade metadata). Audit events for limit reached and usage thresholds use stable `commercial.*` action names. Inbox lifecycle audit assertions were updated so saturation at `inbox.max_active=1` correctly expects threshold audits alongside `inbox.created`.

## Subscription lifecycle

Trial / Active / Cancel-at-period-end / Cancel-immediate / Expire / Renew paths are centralized. Overlapping access-granting subscriptions are rejected under row locks. Free access is effective-plan fallback without orphan Free subscription rows. Expiry scheduler coverage exists (`ExpireSubscriptionsCommandTest`).

## Database

UUID PKs, FKs with cascade where expected, and uniqueness for API keys, webhook deliveries, and outbound idempotency/reservations are present. Noted residual: `subscription_usage` lacks a period uniqueness constraint (app-lock enforced).

## Queues

Outbound delivery uses uniqueness + retries + `afterCommit` on schedule dispatch. Webhook dispatch uses `afterCommit` and delivery-row idempotency. Notification jobs guard with `email_sent_at`. Prompt 635 fixed `ProcessInboundMessageJob::failed()` which previously referenced invalid class names and could not record retry exhaustion.

## Dead code / docs

- Removed unused misleading `User::isPremium()`.
- Corrected catalogue docs so inventory key is `inbox.max_active` (not `max_inboxes`).
- Commercial docs reviewed: `COMMERCIAL_USAGE_SUMMARY`, `API_COMMERCIAL_ENTITLEMENT`, `USER_WEBHOOKS`, `SUBSCRIPTION_LIFECYCLE_CONTRACT`, `CENTRAL_ENTITLEMENT_RESOLUTION`.

## Testing

| Filter | Result |
| --- | --- |
| Commercial | PASS (34) |
| Entitlement | PASS |
| Usage | PASS |
| Subscription | PASS |
| Inbox | PASS (4 relational skips) |
| Outbound | PASS |
| Webhook | PASS (2 relational skips) |
| Api | PASS (2 relational skips) |

Intentional skips: SQLite relational concurrency harnesses for inbox / API-key / webhook quotas.

## Static analysis

- Pint applied to dirty Prompt 635 PHP (`ProcessInboundMessageJob`).
- PHPStan analysed changed app paths; backoff iterable typing corrected.
- `git diff --check` passed for Prompt 635 changes.

## Hardening applied in this audit

1. Repair inbound retry-exhausted failure recording.
2. Remove dead `User::isPremium()` helper.
3. Align inbox lifecycle audit expectations with commercial threshold events.
4. Catalogue documentation key consistency.

## Release recommendation

**READY** for commercial-subsystem release consideration on `feature/commercial-plans`, subject to running MySQL/PostgreSQL relational CI before production cutover. Billing/payment gateways remain explicitly out of scope for Batch 626–635.
