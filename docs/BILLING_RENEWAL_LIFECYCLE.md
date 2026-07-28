# Billing renewal lifecycle

Prompt 644 adds scheduled renewal, grace, expiry, and recovery to the existing provider-neutral billing pipeline. It does not charge a saved payment method and does not introduce another billing, entitlement, or activation engine.

## States

The documented states are `trial`, `active`, `renewal_due`, `grace`, `expired`, and `cancelled`.

- Eligible auto-renewing subscriptions move from `active` to `renewal_due` when an idempotent renewal order is created.
- An unpaid subscription moves to `grace` when its paid term ends.
- A grace subscription moves to `expired` at its configured grace boundary.
- Trials expire at `trial_ends_at`; no implicit extension is applied.
- Cancelled subscriptions never enter scheduled renewal.
- A verified paid renewal order recovers either a grace or expired subscription through `PaidSubscriptionActivationService`.

No lifecycle transition deletes inboxes, messages, attachments, usage history, ledger entries, or subscriptions.

## Entitlements during grace

`CommercialEntitlementResolver` continues to use the central `EntitlementService`. A grace subscription remains discoverable as the current lifecycle subscription, but its effective feature plan resolves to the canonical Free plan. This keeps login and billing surfaces available while premium features, API allowances, outbound capabilities, and storage extensions remain restricted through the existing entitlement checks.

## Scheduled work

The scheduler dispatches three idempotent jobs every five minutes:

- `CreateRenewalOrdersJob` creates a deterministic renewal order before term end.
- `StartGracePeriodJob` starts grace and emits at most one `grace_reminder` event per subscription per calendar day.
- `ExpireSubscriptionsJob` expires ended trials and elapsed grace periods.

The legacy `subscriptions:expire` safety command remains scheduled after these jobs for non-renewing active subscriptions.

The jobs emit `renewal_due`, `grace_started`, `grace_reminder`, `expired`, and `subscription_recovered` domain events. They never send email directly.

## Configuration

```env
BILLING_GRACE_DAYS=7
BILLING_RENEWAL_LEAD_DAYS=3
BILLING_TRIAL_DAYS=14
BILLING_LIFECYCLE_BATCH_SIZE=100
```

Values outside the supported bounds fail closed when scheduled lifecycle work runs. Grace is 1–90 days, renewal lead is 1–30 days, and batch size is 1–1000.

## Financial authority

Browser returns, client timestamps, and user-provided renewal requests cannot extend a subscription. A provider callback must pass the existing Prompt 639 verification boundary and Prompt 638 processing pipeline, after which the existing paid-order activation service performs the renewal.
