# Manual USDT TRC20 billing

Prompt 642 adds `manual_crypto` as a provider adapter. It does not create a second
billing system: checkout uses `CheckoutService`, an approved claim becomes a
`VerifiedProviderEvent`, and Prompt 638 performs ledger mutation and subscription
activation.

## Supported scope

- Asset: USDT only
- Network: TRON/TRC20 only
- Verification: authorized manual review
- Refunds: unsupported
- Blockchain/API confirmation: not performed and never represented

The submitted amount, TXID, and optional screenshot are claims/evidence. A
screenshot is stored on a private Laravel disk and is never payment authority.
Only an approved record with `evidence_status=manually_verified` can cross the
provider verification boundary.

## Configuration

Enable the provider explicitly:

```dotenv
BILLING_ENABLED_GATEWAYS=fake,sslcommerz,stripe,manual_crypto
MANUAL_CRYPTO_ENABLED=true
MANUAL_CRYPTO_TRC20_ADDRESS=T...
MANUAL_CRYPTO_PRIMARY_WALLET_ENABLED=true
MANUAL_CRYPTO_PRIMARY_WALLET_CREATED_AT=2026-07-28T00:00:00+06:00
MANUAL_CRYPTO_EVIDENCE_DISK=local
MANUAL_CRYPTO_MAX_SCREENSHOT_KB=5120
```

The configured address must be a 34-character TRON Base58 address. Wallet entries
also carry an ID, priority, rotation group, creation time, and optional disabled
time. A checkout selects only an enabled wallet and stores an encrypted immutable
snapshot. Disabling or rotating that wallet prevents new selection without
invalidating existing claims.

Production must use HTTPS for `APP_URL`, since checkout instruction links are
temporary signed HTTPS URLs. The first release accepts USD-denominated orders and
uses the fixed USDT/USD amount snapshot; it does not perform exchange-rate
conversion.

## Flow and API

1. Create checkout with gateway `manual_crypto`.
2. Open its signed `checkout_url` to obtain asset, network, wallet, exact amount,
   and expiry.
3. The owner submits `txid`, decimal `amount`, and optionally `screenshot` to
   `POST /api/v1/billing/orders/{order}/manual-crypto-claims`.
4. The owner can read the owner-safe claim timeline at
   `GET /api/v1/billing/manual-crypto-claims/{claim}`.
5. A platform administrator with the dedicated billing-review ability uses the
   `/api/v1/admin/billing/manual-crypto-claims` endpoints to start, reject,
   reopen, or approve review. Approval and rejection require a reason.

Approval rechecks ownership-independent order state, expiry, paid status, amount,
and immutable checkout snapshot. A submitter cannot approve their own claim.
The unique database key on `(network, txid)` prevents a normalized TXID from
funding multiple orders. Repeated provider-event ingestion remains idempotent.

## Operations and audit

Review history is append-only. Audit records cover submission, duplicate TXID,
review start, approval, rejection, reopening, expired claims, already-paid
orders, and unavailable wallets. Never describe a manual decision as blockchain
confirmation.

For rotation, add/enable the replacement wallet, give it the higher priority,
then disable the old entry. Keep old configuration metadata available while
claims referencing its encrypted checkout snapshot remain reviewable.

Common failures:

- `payment_gateway_unavailable`: provider disabled, no enabled valid wallet, or
  unsupported order currency.
- `duplicate_crypto_txid`: the normalized TRON TXID already exists.
- `checkout_expired` / `order_already_paid`: review cannot create a financial
  event.
- `crypto_amount_mismatch`: claimed USDT does not match the immutable expected
  amount.
- `self_approval_forbidden`: reviewer and submitter are the same user.

This release intentionally excludes QR codes, other assets/networks, blockchain
explorers, automatic confirmations, exchange rates, webhooks, and refunds.
