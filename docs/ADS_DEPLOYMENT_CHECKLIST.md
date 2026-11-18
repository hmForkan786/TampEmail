# Ads Deployment Checklist

Use before enabling ads in a production environment.

## Pre-deploy

- [ ] Migrations applied: `2026_08_01_100000_create_ads_management_tables`
- [ ] Seed placements: `php artisan db:seed --class=AdPlacementSeeder`
- [ ] Commercial catalogue includes `ads.visible` (Free true / Premium false)
- [ ] `config/ads.php` present; no secrets committed

## Environment

| Variable | Production guidance |
| --- | --- |
| `ADS_ENABLED` | `true` only after first campaign QA |
| `ADS_PREMIUM_HIDE` | `true` |
| `ADS_PROVIDER` | default hint only (`google_adsense`) — campaigns choose provider |
| `ADS_ADSENSE_PUBLISHER_ID` | live `ca-pub-…` when using AdSense |
| `ADS_ADSENSE_RESPONSIVE` | usually `true` |
| `ADS_REQUIRE_HTTPS_URLS` | `true` |
| `ADS_IMPRESSION_RETENTION_DAYS` / `ADS_CLICK_RETENTION_DAYS` | default `90` |
| Scheduler toggles | leave enabled |

## Cutover

1. Deploy code + migrate + seed placements.
2. Create a **House Ads** promotion campaign (upgrade) targeted Free-only; QA
   on Free and Premium accounts.
3. Create monetization campaign (AdSense or Direct Banner); verify Free sees
   it and Premium does not.
4. Confirm `GET /api/v1/ad/{placement}?track=0` returns expected JSON.
5. Confirm Filament **Ads Control** emergency stop engages/releases.
6. Confirm scheduler entries present (`php artisan schedule:list`).
7. Run `php artisan ads:health` → exit 0.

## Rollback

1. Engage emergency stop **or** `ADS_ENABLED=false`.
2. Pause campaigns in Filament (status → paused).
3. Optional: leave tables in place; statistics prune continues safely.

## Security review

- [ ] No AdSense script/business logic hardcoded in product views
- [ ] Custom HTML campaigns reviewed; sanitizer allow-list sufficient
- [ ] Admin mutations limited to `isPlatformAdmin()`
- [ ] Public ad API throttled (`throttle:ads`)

## Post-deploy

- [ ] Document first revenue entry process for finance
- [ ] Link this checklist from the ops master runbook if not already indexed

See [`ADS_ARCHITECTURE.md`](ADS_ARCHITECTURE.md) and
[`ADS_OPERATIONS_RUNBOOK.md`](ADS_OPERATIONS_RUNBOOK.md).
