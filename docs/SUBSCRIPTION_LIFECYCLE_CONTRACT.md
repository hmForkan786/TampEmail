# Subscription lifecycle contract

The canonical states are `trial`, `active`, `cancelled`, and `expired`.
Only trial and active grant access, subject to the exact date and active-plan
rules in the central entitlement resolver.

Allowed mutations are trial to active/cancelled/expired, active to
cancelled/expired, and explicit cancelled/expired reactivation or renewal.
Activation requires an active user and plan, a non-expired term, and no
overlapping access-granting subscription. Repeating identical effective
mutations is idempotent and produces no duplicate audit.

Immediate cancellation sets `cancelled`, ends access now, disables renewal,
and reverts resolution to canonical Free. Period-end cancellation keeps the
status active, sets `cancel_at_period_end`, records request time, disables
renewal, and preserves access until the exclusive `ends_at` boundary.

`subscriptions:expire --dry-run --batch=100` reports candidates.
`subscriptions:expire --batch=100` processes active/trial rows in deterministic
bounded batches with a transaction, row lock, and lifecycle recheck. It runs
every five minutes with `withoutOverlapping()`. Concurrent runners cannot
produce two effective transitions or audit rows for the same state.

Expiry and cancellation preserve subscription, payment, and usage history.
No Free subscription row is created; the resolver supplies the Free plan.
Renewal is an explicit domain operation and never fabricates payment records.
Usage-period initialization for billing renewals remains deferred until the
payment lifecycle defines a confirmed new term.

Lifecycle audit actions are `subscription.activated`,
`subscription.trial_started`, `subscription.cancel_requested`,
`subscription.cancelled`, `subscription.expired`,
`subscription.reactivated`, and `subscription.renewed`. Notifications and
gateway-driven mutation remain deferred.
