# Billing Checkout Security

## Trust boundaries

- Identity comes from the authenticated API key; request-body `user_id` is not
  accepted.
- Amount and currency come only from the server-side plan snapshot.
- Payment success comes only from verified provider events.
- Unknown and disabled gateways fail closed.

## Redirect policy

Relative application paths and configured first-party HTTPS hosts are accepted.
Protocol-relative URLs, embedded credentials, control characters, unsafe
schemes, malformed URLs, and untrusted hosts are rejected before checkout.
Provider checkout destinations must be absolute HTTPS URLs.

Allowed hosts derive from `APP_URL` and `BILLING_ALLOWED_REDIRECT_HOSTS`.
Production must configure only owned hosts.

## Replay and sensitive data

Per-owner idempotency uniqueness plus a normalized fingerprint prevents
duplicate-click orders and detects changed payloads. Public responses exclude
provider payloads, secrets, audit metadata, encryption material, and customer
details. Checkout URLs are encrypted at rest. Audit records contain safe IDs,
gateway/plan identifiers, and bounded error codes only.

## Ownership and abuse protection

Order reads and mutations are owner-scoped and return not-found for another
owner. Checkout endpoints have per-owner and per-IP rate limits. The browser
return is signed and independently rate-limited; it redirects to a pending
verification state and never mutates payment status.

## Audit coverage

Lifecycle events cover reuse, idempotency conflict, session creation/failure,
resume, cancellation, and expiry, in addition to the existing billing order
event. Raw provider query strings and checkout tokens are not audit payloads.

## Relational concurrency

Database constraints are authoritative for owner/idempotency and provider
session references. SQLite verifies sequential idempotency semantics. True
parallel duplicate requests and row-lock behavior remain a MySQL/PostgreSQL CI
test responsibility.
