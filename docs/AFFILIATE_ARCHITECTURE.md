# Affiliate Architecture

Prompt 662 establishes a **provider-neutral Affiliate Management System** on top of
Temail’s verified billing pipeline. Affiliates earn commission only after a
billing order is confirmed paid through `PaymentProcessingService`.

## Authoritative conversion flow

```text
Affiliate link
→ Visitor attribution
→ User signup / checkout attribution link
→ Billing checkout
→ Verified paid order
→ Affiliate conversion
→ Commission ledger
→ Eligible balance
→ Withdrawal request
→ Admin review
→ Manual payout confirmation
```

## What the affiliate system never does

* Pay a billing order
* Activate a subscription
* Trust a payment-provider browser return or unverified callback
* Store an editable wallet balance as financial truth
* Calculate commissions inside ads/provider adapters

## Frozen payment integration

```text
Verified paid BillingOrder
        ↓
RecordAffiliateConversionJob (afterCommit)
        ↓
AffiliateConversionService (idempotent on billing_order_id)
        ↓
Immutable commission ledger entry (pending)
        ↓
Maturity hold → available
        ↓
Withdrawable balance projection
```

Hook points: `PaymentProcessingService::recordSuccessfulPayment` and `markPaid`,
dispatched beside `ActivatePaidSubscriptionJob` and gated by `affiliates.enabled`.

## Domain models

| Model | Purpose |
| --- | --- |
| `AffiliateProfile` | One affiliate account per user; encrypted payout details |
| `AffiliateCommissionPlan` | Percentage (bps) or fixed terms; hold/cookie windows |
| `AffiliateAttribution` | Hashed visitor click window |
| `AffiliateConversion` | One row per `billing_order_id`; plan snapshot |
| `AffiliateCommissionEntry` | Append-only ledger |
| `AffiliateWithdrawal` | Manual payout request state machine |
| `AffiliateFraudFlag` | Deterministic fraud decisions |

## Attribution

* Cookie: opaque 64-hex visitor token (`HttpOnly`, `SameSite=Lax`, Secure in prod)
* Models: `first_click` / `last_click` (config)
* Public route: `/r/{affiliateCode}` + `?ref=` capture middleware
* Open redirects blocked via allow-listed relative paths only

## API auth decision

Affiliate owner APIs use **`api.key` authentication without new `ApiKeyScope`
values**, matching billing checkout routes. Adding `affiliate:read|write|withdraw`
would expand the canonical scope allowlist and break locked registry tests /
issuance contracts. Owner isolation is enforced by scoping every query to the
caller’s own `AffiliateProfile`.

Routes live under `/api/v1/affiliate/*`.

## Ledger accounting

Amounts are immutable. Status may move `pending → available` (maturity) or
`held → paid` (payout). Reversals, holds, releases, and payouts append compensating
entries.

`AffiliateBalanceService` projects `pending`, `available`, `held`, `paid`,
`reversed`, and `net_available` from the ledger.

## Ads boundary

Ads may render affiliate recruitment/promotion creatives (`AdPromotionKind::Affiliate`)
but **must not** calculate commissions or mutate the affiliate ledger.

## Admin

Filament navigation group **Affiliates** (platform-admin only): profiles, plans,
attributions, conversions, ledger, withdrawals, fraud review, control page.
