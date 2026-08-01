# Ads Operations Runbook

Companion to [`ADS_ARCHITECTURE.md`](ADS_ARCHITECTURE.md).

## Daily / weekly checks

```bash
php artisan ads:health
```

Exit `0` = healthy JSON. Inspect:

- `emergency_stop`
- `active_campaigns` / `active_placements`
- `providers_available`
- `statistics` (impressions, clicks, CTR, revenue_minor)

Filament: **Ads → Ads Control** for live emergency stop and summary metrics.

## Scheduler

| Command | Cadence | Role |
| --- | --- | --- |
| `ads:expire-campaigns` | hourly | Active → expired when `ends_at` passed |
| `ads:refresh-budgets` | daily | Reset daily impression/click counters |
| `ads:prune-statistics --confirm` | daily | Retention prune |

Toggles: `ADS_SCHEDULER_EXPIRE`, `ADS_SCHEDULER_BUDGETS`, `ADS_SCHEDULER_PRUNE`.

## Incidents

### Serve nothing (global)

1. Filament **Ads Control → Engage emergency stop**, or
2. `Cache::forever('ads:emergency_stop', true)` via tinker, or
3. Set `ADS_ENABLED=false` and reload config.

### Premium seeing monetization ads

1. Confirm plan feature `ads.visible` is `false` for Premium.
2. Confirm `ADS_PREMIUM_HIDE=true`.
3. Confirm campaign `purpose=monetization` (promotions intentionally may show).

### No ads on Free

1. `ads:health` — placements seeded? campaigns active?
2. Targeting audience too narrow?
3. Cap / daily budget reached → status `budget_reached`?
4. Provider config invalid (AdSense publisher/slot)?

### XSS / unsafe creative

Decision engine fails closed on unsafe render. Sanitize at admin save via
provider `validateConfig`. Do not embed raw third-party HTML in Blade outside
`AdRenderPayload`.

## Revenue

Record manual network payouts under **Ads → Revenue** (`amount_minor` in
currency minor units). Automated AdSense payout sync is out of scope for
Prompt 661.

## Monitoring hooks

- Health: `php artisan ads:health`
- Audit actions: `ads.emergency_stop.*`, `ads.campaign.*`
- Domain events for downstream listeners (optional)

Escalate with campaign id, placement key, and `ads:health` JSON.
