# Provider-Neutral Billing Checkout

Prompt 637 extends the Prompt 636 billing domain through the existing
`CheckoutService`. It does not add a second checkout domain and does not
activate subscriptions.

## Lifecycle

1. An authenticated API-key owner submits `POST /api/v1/billing/checkout`.
2. Client-controlled amount, currency, discount, and tax fields are rejected.
3. Redirect destinations are normalized as first-party HTTPS or relative URLs.
4. Eligibility validates the user, target plan, price, current subscription,
   renewal/upgrade direction, and any existing checkout.
5. A unique `(user_id, idempotency_key)` request reserves the operation and
   stores a SHA-256 fingerprint.
6. `BillingOrderService` snapshots plan price and currency; discount and tax are
   zero for this prompt.
7. A session row is committed before the gateway call, so provider I/O is not
   performed inside a long database transaction.
8. A validated provider result is persisted; the session becomes pending and
   the order becomes processing.

A browser return never marks an order paid. Only the verified provider-event
pipeline may record payment and activate a subscription.

## Idempotency

The fingerprint covers plan, normalized gateway, billing cycle, resolved order
type, normalized redirects, and client reference. Sensitive metadata is not
stored raw in the fingerprint.

- Same owner/key/fingerprint returns the existing order and usable session.
- The same key with different data returns `idempotency_conflict`.
- Database uniqueness prevents duplicate request reservations.
- Failed provider attempts preserve a failed session and retryable request.

## Order and session distinction

`billing_orders` is the payable intent and immutable financial snapshot.
`billing_checkout_sessions` is provider-navigation state, not a ledger.
Checkout URLs are encrypted at rest. Payment transactions remain the ledger.

Session states are `created`, `pending`, `redirected`, `completed`, `failed`,
`cancelled`, and `expired`; invalid terminal transitions are rejected.

## API and lifecycle operations

- `POST /api/v1/billing/checkout`
- `GET /api/v1/billing/orders/{billingOrder}`
- `POST /api/v1/billing/orders/{billingOrder}/resume`
- `POST /api/v1/billing/orders/{billingOrder}/cancel`
- `GET /billing/return/{provider}` (signed browser-return foundation)

Reads, resume, and cancellation are owner-scoped. Paid/refunded/charged-back
orders cannot be resumed or cancelled. Resume returns the current usable
session. The fake gateway uses internal cancellation because it has no remote
provider state.

`php artisan billing:expire-checkouts` expires bounded batches of unpaid
orders and active sessions. It runs every five minutes without overlap.

## Future adapters

The fake gateway produces an HTTPS URL and reference for contract testing only.
Future adapters must return the resolved provider name, a non-empty unique
reference, a valid HTTPS checkout URL, and a sensible expiry, without exposing
provider secrets.
