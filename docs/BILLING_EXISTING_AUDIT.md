# Billing Existing Audit

Audit date: 2026-07-28. Scope: Prompt 636 pre-architecture inventory.

## Reusable

| Asset | Notes |
| --- | --- |
| `plans` table/model | Canonical plan pricing (`price_monthly`, `price_yearly`, `currency`); snapshot at order time. |
| `subscriptions` table/model | Lifecycle record with price/currency snapshot fields; link from paid orders. |
| `SubscriptionLifecycleService` | Sole authority for activate/renew/cancel/expire; billing must call this, never mutate status directly. |
| `EntitlementService` | Effective-plan resolver after activation; unchanged by billing architecture. |
| `AuditLogWriter` | Append-only audit infrastructure for billing events. |
| `BillingCycle` enum | Monthly/yearly/lifetime intervals for subscription terms. |
| `SubscriptionStatus` enum | Trial/active/cancelled/expired lifecycle states. |

## Extend / migrate

| Asset | Action |
| --- | --- |
| Payment ledger | New append-only `payment_transactions` table linked to `billing_orders`; do not overload `subscription_transactions`. |
| Checkout lifecycle | New `billing_orders` state machine; no checkout flow existed previously. |
| Provider integration | New `PaymentGateway` contract + registry; legacy `PaymentGateway` enum remains for old rows only. |
| Webhook ingestion | New `payment_provider_events` inbox distinct from user webhook endpoints. |
| Subscription activation | New `PaidSubscriptionActivationService` adapter over `SubscriptionLifecycleService`. |

## Legacy (retain, do not delete)

| Asset | Reason |
| --- | --- |
| `subscription_transactions` table/model | Historical schema with decimal amounts and `gateway_response` JSON; kept for backward compatibility. |
| `PaymentGateway` enum (`stripe`, `paddle`) | Cast on legacy `SubscriptionTransaction`; not used by new ledger. |
| `PaymentStatus` enum | Legacy transaction statuses on `subscription_transactions`. |
| Plan `price_monthly`/`price_yearly` decimals | Existing admin/catalogue format; converted to minor units at order creation only. |

## Duplicate source-of-truth risks (resolved by Prompt 636)

| Risk | Resolution |
| --- | --- |
| Legacy `subscription_transactions` vs new ledger | New canonical ledger is `payment_transactions`; legacy table is read-only heritage. |
| Plan live price vs charged amount | `billing_orders` snapshot minor-unit totals at creation. |
| Provider payload vs internal state | Internal state machines are authoritative; provider events are inputs only. |
| Direct subscription mutation | Forbidden; activation goes through lifecycle service only. |

## Current payment → subscription path

Before Prompt 636 there is **no automated payment-success → subscription-activation pipeline**. Subscriptions are created and transitioned manually (admin/tests/seeds) via `SubscriptionLifecycleService`. Legacy `subscription_transactions` records payment outcomes against an **existing** subscription but does not drive activation. Prompt 636 introduces the coordinated billing order → payment ledger → activation contract.
