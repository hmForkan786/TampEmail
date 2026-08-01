# Billing State Machines

Settlement transitions are documented in
[Billing Settlements](BILLING_SETTLEMENTS.md).

Internal billing state is enforced by dedicated state machine services. Invalid transitions throw `InvalidBillingStateTransitionException`.

## BillingOrderStatus

| From | Allowed to |
| --- | --- |
| `pending` | `processing`, `cancelled`, `expired`, `failed` |
| `processing` | `paid`, `failed`, `cancelled`, `expired` |
| `paid` | `refunded`, `partially_refunded`, `charged_back` |
| `partially_refunded` | `partially_refunded`, `refunded`, `charged_back` |

Terminal statuses: `cancelled`, `expired`, `refunded`, `charged_back` (no outbound transitions defined).

Invalid examples rejected:

- `paid → pending`
- `charged_back → paid`
- `cancelled → processing`

## PaymentTransactionStatus

| From | Allowed to |
| --- | --- |
| `pending` | `succeeded`, `failed`, `cancelled` |
| `succeeded` | `reversed` |

Failed transactions cannot become `succeeded` in place; a new provider event/transaction row is required.

## PaymentProviderEventStatus

| From | Allowed to |
| --- | --- |
| `received` | `processing`, `ignored` |
| `processing` | `processed`, `failed`, `ignored` |
| `failed` | `processing` (retry) |

## Activation metadata

Billing orders store `metadata.activation_status`:

- `pending`
- `succeeded`
- `failed`
- `reconciliation_required`

Payment may succeed while activation remains `failed` or `reconciliation_required`; financial rows are never rolled back.
