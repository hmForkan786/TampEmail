# Affiliate Operations Runbook

## Enable the module

1. Set `AFFILIATE_ENABLED=true` in the environment.
2. Confirm `AFFILIATE_REGISTRATION_MODE=manual_approval` (recommended).
3. Seed the default plan: `php artisan db:seed --class=AffiliateCommissionPlanSeeder`
4. Verify: `php artisan affiliates:health`

Keep maturity/prune schedulers **off** until the first production cutover is validated.

## Approve affiliates

Filament → Affiliates → AffProfiles → Approve / Reject / Suspend / Reactivate / Close.

Suspended affiliates:
* cannot receive new attribution
* cannot earn new commissions
* cannot withdraw
* historical ledger remains intact

## Manual payout process

1. Affiliate submits `POST /api/v1/affiliate/withdrawals` (hold reserved).
2. Admin **Start review** → **Approve** (or Reject → release).
3. Ops executes external payout (bank / PayPal / Wise / USDT) offline.
4. Admin **Mark processing** → **Mark paid** with `external_reference`.
5. Confirm ledger shows exactly one `payout` entry for the withdrawal.

Never mark paid without an external reference. No automated payout provider in Prompt 662.

## Scheduled jobs

| Command | Suggested cadence | Flag |
| --- | --- | --- |
| `affiliates:mature-commissions` | hourly | `AFFILIATE_MATURITY_SCHEDULER_ENABLED` |
| `affiliates:expire-attributions` | hourly | `AFFILIATE_ATTRIBUTION_EXPIRE_ENABLED` |
| `affiliates:prune-attributions --confirm` | daily | `AFFILIATE_ATTRIBUTION_PRUNE_ENABLED` |

All use `withoutOverlapping`. Prune requires `--confirm` for destructive deletes; `--dry-run` is safe.

Optional: `affiliates:balance-audit`

## Health

```bash
php artisan affiliates:health
```

Checks: enabled flag, active plan, maturity backlog, stale withdrawals, fraud review backlog.

## Fraud review

Filament → Affiliate Fraud Review. Decisions: `allow` / `manual_review` / `reject`.
Self-referral always rejects. Fast conversion / same IP hash → manual review.

## Recovery

| Symptom | Action |
| --- | --- |
| Duplicate conversion job | Safe — unique on `billing_order_id` |
| Stuck withdrawal `requested` | Start review or cancel (releases hold) |
| Paid without reference | Impossible via service contract |
| Maturity backlog | Run `affiliates:mature-commissions` |
| Ledger confusion | Recompute via `AffiliateBalanceService`; ledger is source of truth |

## Kill switch

Set `AFFILIATE_ENABLED=false`. Existing ledger preserved; no new clicks/conversions/jobs dispatched from payment success.
