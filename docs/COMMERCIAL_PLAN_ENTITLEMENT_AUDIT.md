# Commercial Plan and Entitlement Contract

**Prompt 626 — audit outcome**

This document is the contract for the commercial-plan batch. It records the
current implementation and the deliberate decisions required before plan
enforcement is expanded. It does not change an existing user's access.

## Current state

The data model is suitable as a foundation: `plans`, `features`,
`feature_plan`, `subscriptions`, and usage records already exist. The central
`EntitlementService` resolves an attached, active catalog feature for the
user's current `active` or `trial` subscription; pivot values override catalog
defaults. Inbox, outbound, mail-server-pool, API-key quota, and outbound
retention code already consume it.

The commercial contract is not complete yet:

- There is no canonical Free or Premium plan seed/provisioning path.
- The feature catalog seed contains outbound features only; inbox, mail-pool,
  and API-key features are created ad hoc by tests or application paths.
- A missing metered outbound feature means unlimited, while a missing inbox or
  API-key quota also skips enforcement. That is unsafe as the Free fallback.
- Subscription selection does not currently reject an inactive plan or an
  `ends_at` date in the past. `User::isPremium()` separately uses its `hasOne`
  relation and therefore can disagree with the central resolver.
- Cancellation, expiry, failed-payment, grace-period and plan-change rules
  have not been modelled as a commercial lifecycle.

## Authoritative resolution contract

Prompt 630 must make this resolver the only authority for product access:

1. Resolve the current subscription deterministically.
2. A subscription grants access only when its plan is active and it is within
   its effective time window.
3. Eligible states are `active` and `trial`; a cancellation retains access
   only until its explicit effective end date. `expired` and payment-failed
   states do not grant Premium access.
4. If no eligible subscription exists, resolve the single active `free` plan.
   If that plan is unavailable, fail closed for paid capabilities and return a
   diagnosable configuration error—not an implicit unlimited entitlement.
5. A feature is granted only when it is active in the catalog and attached to
   the resolved plan. Pivot `feature_value` overrides catalog `default_value`.
6. A missing feature means **not entitled**. Unlimited must be explicit with
   the documented null limit value on an attached feature.

This contract eliminates the current ambiguity where feature absence can mean
either unlimited access or no access.

## Initial plan catalogue

The following keys are the minimum stable catalogue. Values use JSON payloads
so limits remain versionable without schema changes.

| Capability | Feature key | Free | Premium |
| --- | --- | --- | --- |
| Inbox count | `max_inboxes` | finite limit | higher or explicit unlimited limit |
| Inbox retention | `inbox_retention_days` | finite days | higher days |
| Custom aliases | `custom_aliases` | disabled | enabled, optionally limited |
| Send / reply / forward | `send_email`, `reply_email`, `forward_email` | explicit business decision | enabled |
| Outbound messages / recipients / attachment bytes | existing `outbound_*_per_period` keys | finite monthly limits | higher or explicit unlimited limits |
| Outbound content retention | `outbound_retention_days` | finite days | higher days |
| Per-message attachment size | `max_attachment_bytes` | finite bytes | higher bytes |
| API keys | `max_api_keys` | finite limit or zero | higher limit |
| API scopes | `api_read`, `api_write` | explicit booleans | explicit booleans |
| User webhooks | `webhooks` | disabled | enabled, optionally limited |
| Advertising | `ads_visible` | enabled | disabled |
| Mail-server access | `mail_server_pools` | `public`/`standard` as configured | includes `premium` as configured |

Exact numeric limits, whether Free may send outbound mail, and the allowed
mail-server pool names are product decisions to be supplied in Prompt 627.
They must be seeded deliberately, never inferred from a missing row.

## Enforcement rules for the next prompts

- Every create or mutation path must ask the central resolver before state is
  written; existing resources must remain readable after downgrade or expiry.
- Quota exhaustion returns a stable, machine-readable entitlement/limit error,
  preserves existing data, and offers an upgrade path in web UX.
- API-key scopes require both the existing role check and the plan scope
  entitlement. Role capability alone must not confer a paid product feature.
- Provider delivery webhooks are infrastructure callbacks, not the proposed
  user-facing `webhooks` feature; they must never be disabled by a plan.
- Admins may manage plans and subscriptions, but changes need audit records
  and must not silently expand access through catalog seeding.

## Delivery gates

Before Prompt 631 begins, add canonical plan provisioning and resolver tests
for Free fallback, inactive plan, past end date, trial, cancellation, expiry,
and feature absence. Before billing work, replace the lifecycle enum/contract
with the states required for failed payment and grace periods and introduce a
plan-change audit trail.

## Compatibility decision

Existing outbound behaviour remains unchanged during this audit. The current
"missing metered feature means unlimited" rule is legacy compatibility only;
Prompt 630 must remove it from commercial plan resolution after the canonical
Free and Premium mappings have been provisioned and migration-tested.
