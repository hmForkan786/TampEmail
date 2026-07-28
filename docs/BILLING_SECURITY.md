# Billing Security

## Idempotency

| Key | Scope |
| --- | --- |
| `billing_orders (user_id, idempotency_key)` | Checkout/order creation |
| `payment_transactions (billing_order_id, idempotency_key)` | Ledger append |
| `payment_transactions (provider, provider_transaction_id)` | Provider reference uniqueness |
| `payment_provider_events (provider, provider_event_id)` | Webhook replay protection |

Duplicate successful provider events audit `billing.webhook.duplicate` and do not double-activate subscriptions.

## Amount and currency verification

`PaymentProcessingService` compares provider-reported minor units and currency against the snapshotted billing order before marking `paid`. Mismatches fail closed and audit:

- `billing.payment.amount_mismatch`
- `billing.payment.currency_mismatch`

## Sensitive data policy

Never persist:

- Card PAN/CVV
- Mobile PIN/OTP
- Raw provider secrets/tokens

`PaymentPayloadRedactor` strips sensitive keys before storing `payload_redacted` on provider events. Audit logs must not contain raw provider payloads.

## Concurrency

- Order transitions use `lockForUpdate`.
- Activation job implements `ShouldBeUnique` per billing order.
- Webhook persistence occurs before async processing; jobs dispatch `afterCommit`.

SQLite tests do not prove production lock behavior; MySQL/PostgreSQL relational CI remains required for checkout/webhook race scenarios.

## Audit events

Billing operations emit:

`billing.order.created`, `billing.checkout.created`, `billing.payment.succeeded`, `billing.payment.failed`, `billing.payment.amount_mismatch`, `billing.payment.currency_mismatch`, `billing.webhook.duplicate`, `billing.subscription.activated`, `billing.subscription.activation_failed`, `billing.refund.succeeded`, `billing.chargeback.received`, `billing.reconciliation.required`.

## Fail-closed cases

- Unknown/disabled provider
- Unverified webhook (gateway responsibility)
- Invalid state transition
- Conflicting provider transaction reference across orders
- Malformed/unknown provider state in strict paths
