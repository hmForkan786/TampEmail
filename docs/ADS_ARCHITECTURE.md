# Ads Management Architecture

Prompt 661 establishes a **provider-neutral Ads Management Platform** on top of
the commercial entitlement system. Monetization ads and internal promotions
share one decision pipeline; views never contain provider-specific business
logic.

## Frozen pipeline

```text
User
  ↓
Commercial Entitlement (ads.visible / purpose gate)
  ↓
Ad Decision Engine
  ↓
Campaign Selection
  ↓
Provider Adapter
  ↓
Rendered Advertisement
```

**Forbidden:** billing redesign, commercial redesign, hardcoded AdSense in
views, ads business logic inside Blade/React beyond consuming `AdDecision`.

## Principles

1. Provider adapters only render; they never decide eligibility.
2. Free plan may see monetization ads; Premium is ad-free for monetization
   (`ads.visible = false`, `ADS_PREMIUM_HIDE=true`).
3. Internal promotions (`purpose=promotion`) bypass the monetization
   entitlement gate and use targeting only — so upgrade banners, coupons,
   maintenance notices, and partner promos live in the same system.
4. Emergency stop fails closed for every placement.
5. HTML/URLs are sanitized fail-closed (HTTPS preferred, no `javascript:` /
   event handlers).

## Domain models

| Model | Purpose |
| --- | --- |
| `AdPlacement` | Named surface (`homepage_top`, `inbox_list`, …) |
| `AdCampaign` | Provider + purpose + schedule + caps + targeting |
| `AdImpression` / `AdClick` | Statistics ledger |
| `AdRevenueEntry` | Manual revenue reporting |

## Services

| Service | Responsibility |
| --- | --- |
| `AdDecisionEngine` | Ordered decision: stop → enable → placement → campaign → provider → render |
| `AdCampaignSelector` | Priority-ordered eligible campaign pick |
| `AdTargetingEvaluator` | Audience / country / device / language / theme + commercial gate |
| `AdProviderRegistry` | Slug → adapter (Billing-style registry) |
| `AdStatisticsService` | Impression/click/CTR/revenue + prune |
| `AdEmergencyStopService` | Cache-backed kill switch |
| `AdCampaignLifecycleService` | Enable/disable/expire/budget refresh |
| `AdHealthCheckService` | Ops health JSON |
| `AdContentSanitizer` | XSS / mixed-content / invalid URL guards |

## Provider abstraction

Contract: `App\Contracts\Ads\AdProvider`

| Slug | Adapter | Status |
| --- | --- | --- |
| `google_adsense` | `GoogleAdSenseProvider` | Implemented |
| `direct_banner` | `DirectBannerAdProvider` | Implemented |
| `house_ads` | `HouseAdsProvider` | Implemented (internal promotion engine) |
| `custom_html` | `CustomHtmlAdProvider` | Implemented |
| `adsterra`, `media_net`, `ezoic`, `propeller_ads` | — | Enum reserved; register in `config/ads.php` |

## Commercial integration

| Plan | `ads.visible` | Monetization ads | Promotions |
| --- | --- | --- | --- |
| Free | enabled | Allowed (targeting permitting) | Targeting |
| Premium | disabled | Hidden when `ADS_PREMIUM_HIDE` | Targeting only |

Per-plan override remains via Filament commercial feature editor
(`CommercialPlanManagementService`), subject to Free-plan invariants.

## Internal promotion engine

House ads / `purpose=promotion` cover:

- Free → Premium upgrade
- Affiliate / partner
- Feature announcement
- Maintenance notice
- Coupon / seasonal offer
- Blog promotion

No separate banner management system is required.

## API

| Method | Path | Auth |
| --- | --- | --- |
| `GET` | `/api/v1/ad/{placement}` | Public (throttled) |
| `POST` | `/api/v1/ad/click` | Public (throttled) |

Response shape: `AdDecision::toArray()` — `show`, `reason`, `provider`,
`purpose`, `impression_id`, `render`.

Views should use `<x-ad-slot placement="dashboard" />` or the JSON API.

## Events

`AdRendered`, `AdClicked`, `CampaignEnabled`, `CampaignDisabled`,
`CampaignExpired`, `CampaignBudgetReached`.

## Configuration

See `config/ads.php` and `.env.example` (`ADS_*`). Never commit live AdSense
credentials.

## Future provider checklist

1. Implement `AdProvider` for the slug.
2. Register in `config/ads.php` `providers` map.
3. Add enum case label if new.
4. Cover validate/render with feature tests (no live network in CI).

See also: [`ADS_OPERATIONS_RUNBOOK.md`](ADS_OPERATIONS_RUNBOOK.md),
[`ADS_DEPLOYMENT_CHECKLIST.md`](ADS_DEPLOYMENT_CHECKLIST.md),
[`CENTRAL_ENTITLEMENT_RESOLUTION.md`](CENTRAL_ENTITLEMENT_RESOLUTION.md).
