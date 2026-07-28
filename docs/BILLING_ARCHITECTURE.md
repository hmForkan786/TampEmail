# Billing Architecture

Prompt 636 establishes a provider-agnostic billing and payment foundation on top of the commercial subsystem (Prompts 626–635).

## Principles

1. Provider callbacks never mutate subscriptions directly.
2. Internal state machines are the source of truth; provider payloads are inputs.
3. Money is stored only in minor integer units via the `Money` value object.
4. Provider events and checkout requests are idempotent.
5. Payment success and subscription activation are separate, coordinated steps.
6. Refunds/chargebacks append ledger rows; history is never deleted.

## Domain models

| Model | Purpose |
| --- | --- |
| `BillingOrder` | Purchase/renewal intent with snapshotted totals |
| `PaymentTransaction` | Append-only financial ledger |
| `PaymentProviderEvent` | Webhook/event inbox with idempotency |

Legacy `subscription_transactions` remains for historical records only.

## Services

| Service | Responsibility |
| --- | --- |
| `BillingOrderService` | Idempotent order creation, price snapshot, transitions |
| `CheckoutService` | Checkout orchestration without activation |
| `PaymentProcessingService` | Verified event ingestion, ledger append, order paid transition |
| `PaidSubscriptionActivationService` | Adapter over `SubscriptionLifecycleService` |
| `BillingRefundService` | Refund/chargeback accounting |
| `BillingReconciliationService` | Anomaly detection and recovery markers |
| `BillingEntitlementImpactService` | Central subscription consequence policy |
| `BillingReadModelService` | Internal ops/admin read model |

## Provider abstraction

`PaymentGateway` contract + `PaymentGatewayRegistry` + `PaymentGatewayResolver`.

Prompt 636 ships `FakePaymentGateway` only. Real Stripe/SSLCommerz/bKash/Nagad integrations are future prompts.

## Queues

| Job | Role |
| --- | --- |
| `ProcessPaymentProviderEventJob` | Async provider event processing (`afterCommit`) |
| `ActivatePaidSubscriptionJob` | Idempotent subscription activation retry |
| `ReconcileBillingOrderJob` | Mark orders requiring reconciliation |

## Configuration

See `config/billing.php` and `.env.example` placeholders. Never commit live credentials.

## Future provider checklist

1. Implement `PaymentGateway` for the provider slug.
2. Register class in `config/billing.php` `gateways` map.
3. Enable slug in `BILLING_ENABLED_GATEWAYS`.
4. Map provider webhook signatures and normalized `VerifiedProviderEvent` fields.
5. Add provider-specific integration tests (not live credentials in CI).
6. Run relational concurrency tests on MySQL/PostgreSQL before production cutover.

See also: [`BILLING_EXISTING_AUDIT.md`](BILLING_EXISTING_AUDIT.md), [`BILLING_STATE_MACHINES.md`](BILLING_STATE_MACHINES.md), [`BILLING_SECURITY.md`](BILLING_SECURITY.md).
