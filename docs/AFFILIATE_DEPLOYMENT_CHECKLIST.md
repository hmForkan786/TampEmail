# Affiliate Deployment Checklist

## Pre-deploy

- [ ] Migration `2026_08_01_200000_create_affiliate_management_tables` reviewed
- [ ] `.env` affiliate keys present (see `.env.example`)
- [ ] `AFFILIATE_ENABLED=false` until smoke tests pass
- [ ] Default commission plan seeder ready
- [ ] PaymentProcessingService hook present (RecordAffiliateConversionJob afterCommit)
- [ ] Scheduler flags intentionally false on first deploy
- [ ] Filament Affiliates nav visible to platform admins only

## Deploy

1. `php artisan migrate --force`
2. `php artisan db:seed --class=AffiliateCommissionPlanSeeder --force`
3. `php artisan config:cache`
4. `php artisan route:cache`
5. `php artisan event:cache`
6. `php artisan schedule:list` — confirm affiliate commands appear only when flags enabled
7. `php artisan affiliates:health`

## Smoke

- [ ] Apply as user → pending profile
- [ ] Admin approve → active code
- [ ] `/r/{code}` sets cookie, no open redirect
- [ ] Paid order via fake/provider test path creates one conversion
- [ ] Browser return / checkout alone creates zero conversions
- [ ] Withdrawal request holds balance; mark paid requires external reference
- [ ] `php artisan test --filter=Affiliate`

## Enable

1. `AFFILIATE_ENABLED=true`
2. Optionally enable maturity scheduler after first commissions exist
3. Document support playbook for payout operators

## Rollback

1. Set `AFFILIATE_ENABLED=false` (immediate kill switch)
2. Do **not** drop financial tables if ledger rows exist
3. Leave Filament read-only; stop approving withdrawals
4. Revert code only after ledger export if required for audit
