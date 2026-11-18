# Central entitlement resolution

`EntitlementService` is the single resolver. It does not cache lifecycle
decisions. It selects an active/trial subscription only when `starts_at <= now`,
`ends_at` is null or later than now, and its plan is active. Invalid,
cancelled, expired, future-starting, or inactive-plan subscriptions fall back
to the active plan whose slug is exactly `free`; display names are never used.
If Free is missing or inactive, all access denies.

The end boundary is exclusive: `ends_at == now` is invalid. Boolean access is
granted only for Boolean features with `{enabled: true}`, `1`, or `'1'`.
Missing mappings, nulls, malformed values, and `'false'` deny. `limit()`
returns a non-negative integer; missing, null, malformed, or negative values
are `0`. No missing value represents unlimited.

`featureValue()` remains only for legacy structured consumers. New enforcement
must use `allows()` or `limit()`. Existing callers not yet migrated include
retention, mail-server-pool selection, and outbound usage payload shaping.
Prompt 629 owns lifecycle mutation and expiry transitions.

The implemented mutation and maintenance contract is documented in
[`SUBSCRIPTION_LIFECYCLE_CONTRACT.md`](SUBSCRIPTION_LIFECYCLE_CONTRACT.md).
