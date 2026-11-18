# Affiliate Commission Policy

## Commission base

Default: **order subtotal after discount, excluding tax**.

```text
base_minor = max(0, subtotal_minor − discount_minor)
```

Configurable via `AFFILIATE_COMMISSION_BASE`:
`subtotal_after_discount` | `subtotal` | `total`.

## Rounding

Percentage commissions use **integer floor** arithmetic only:

```text
commission = floor(base_minor × percentage_bps / 10000)
```

Example: `10% = 1000 bps`. Never use floating-point money math.

## Fixed commissions

`fixed_amount_minor` applies only when plan currency matches the order currency.
Mismatch → no commission.

## Caps and thresholds

* `minimum_order_minor` — order `total_minor` below threshold → `0`
* `maximum_commission_minor` — hard cap after calculation
* Negative commission is prohibited

## Hold / maturity

New commission entries start as `pending` with `available_at = now + hold_days`
(plan override or `AFFILIATE_COMMISSION_HOLD_DAYS`, default 14).

Command: `php artisan affiliates:mature-commissions {--dry-run} {--limit=}`

No maturity when the related conversion is `rejected` or `reversed`.

## Eligible order types

| Type | Default |
| --- | --- |
| initial_purchase (`BillingOrderType::Purchase`) | enabled |
| renewal | disabled |
| recovery (order metadata `recovery=true`) | disabled |
| upgrade | enabled |

Zero-value, unpaid, failed, cancelled, and expired checkouts never convert.

## New-customer policy

When `new_customer_only=true` (default), a referred user with a prior paid order
or prior pending/approved conversion does not generate another commission.

Recurring commissions are **disabled by default**.

## Currency

One supported ledger currency set (`AFFILIATE_SUPPORTED_CURRENCIES`, default `USD`).
Unsupported order currency → no commission. **No FX engine** in Prompt 662.

## Snapshots

`commission_plan_snapshot` is frozen on conversion. Later plan edits do not
mutate historical commissions.

## Reversals

`AffiliateCommissionReversalService` appends a negative `reversal` entry.
Original commission amounts are never edited.

* Full reversal marks conversion `reversed`
* Partial reversal: `min(requested, remaining)`; conversion stays until fully reversed
* Available balance may go to zero (or conceptually negative after paid-out clawbacks);
  `net_available` floors at `0` for new withdrawals
* Never reverse from unverified admin claims alone — service is explicit/manual

## Withdrawals

Minimum: `AFFILIATE_MIN_WITHDRAWAL_MINOR` (default 5000).
Request appends `withdrawal_hold`. Reject/cancel appends `withdrawal_release`.
Paid requires `external_reference` and converts hold → paid + payout entry.
