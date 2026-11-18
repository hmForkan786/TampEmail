# Stripe Billing Integration

## Architecture

Stripe is a one-time-payment `PaymentGateway` adapter. It does not use Stripe Billing subscriptions:

```text
CheckoutService → Stripe Checkout Session → hosted Stripe page
browser return → SyncPaymentStatusJob → Checkout Session / PaymentIntent query
signed webhook → Prompt 639 verification → event normalization
               → Prompt 638 ledger/order processing → activation job
```

Browser success and cancel returns are never payment authority. Financial state changes only after an exact-raw-body Stripe signature check or a cross-checked Stripe API query.

## Test-mode setup

```dotenv
STRIPE_ENABLED=true
STRIPE_ENV=test
STRIPE_DEFAULT_ACCOUNT=default
STRIPE_DEFAULT_ACCOUNT_ENV=test
STRIPE_SECRET_KEY=your-test-secret-key
STRIPE_PUBLISHABLE_KEY=your-test-publishable-key
STRIPE_WEBHOOK_SECRET=your-test-endpoint-secret
STRIPE_ALLOWED_CURRENCIES=usd
BILLING_ENABLED_GATEWAYS=fake,sslcommerz,stripe
```

Register or forward webhooks to:

```text
https://your-host.example/api/v1/billing/providers/stripe/callback
```

For local development, use `stripe listen --forward-to http://localhost/api/v1/billing/providers/stripe/callback` and configure the endpoint secret printed by the CLI. Test card details stay on Stripe-hosted Checkout and never enter this application. Keep the billing/default queue worker and scheduler active.

## Live setup

Set `STRIPE_ENV=live`, supply live keys and a live endpoint-specific webhook secret, use public HTTPS, rebuild configuration with `php artisan config:cache`, and run `php artisan billing:stripe-health`. Test keys are rejected in live mode and live keys are rejected in test mode.

Rotate API keys through the deployment secret manager. Rotate endpoint secrets with a bounded overlap using `STRIPE_WEBHOOK_SECRET_RETIRING`, confirm new-secret verification, then remove the retiring secret. Roll back by disabling Stripe checkout while leaving webhook processing and reconciliation available for outstanding payments.

## Supported events

- `payment_intent.succeeded`: successful financial payment
- `payment_intent.payment_failed`: failed attempt
- `payment_intent.canceled`: cancelled attempt
- `checkout.session.completed`: succeeds only when `payment_status=paid`; otherwise remains pending
- `checkout.session.async_payment_succeeded`: successful delayed payment
- `checkout.session.async_payment_failed`: failed delayed payment

The normalizer cross-checks local order, account key, Checkout Session, PaymentIntent, integer amount, and currency. Unknown or malformed events fail closed.

## Idempotency

Local checkout idempotency prevents duplicate logical initialization. The same stable key is sent to Stripe for Checkout Session creation. Stripe Event IDs feed existing provider-event uniqueness, while Prompt 639 replay protection reserves the event ID as a nonce. Multiple success paths remain safe because Prompt 638 will not pay or activate an already-paid order twice. Manual synchronization uses its own deterministic provider event identity.

## Multi-account

Accounts are configured under `billing.stripe.accounts`; the default account is used unless the original order already has a persisted account key. Webhooks try only a bounded set of environment-valid endpoint secrets. Callback metadata cannot switch the locally persisted account. Query and future refund operations resolve the original account.

Account value objects redact secret and webhook keys from JSON. Do not store SDK clients, keys, webhook secrets, PaymentIntent client secrets, full Stripe objects, addresses, or card data.

## Troubleshooting

- Invalid `Stripe-Signature`: confirm exact endpoint secret, unmodified raw body, and clock synchronization.
- Timestamp outside tolerance: correct server UTC time; never expand tolerance without investigation.
- Checkout URL/Session missing: inspect safe API status and idempotency correlation.
- PaymentIntent missing: leave payment pending and reconcile the original Session.
- Session complete but pending: delayed methods require an async-success webhook.
- Payment succeeded but activation delayed: inspect provider-event and activation queues.
- Amount/currency/account mismatch: reject mutation and investigate order/account mapping.
- Duplicate event: expect a safe 2xx acknowledgement and no duplicate ledger effect.
- Return before webhook: synchronization queries Stripe; the return itself changes nothing.
- Webhook before return: normal processing may finish; the later return remains harmless.
- Rate limit/timeout: bounded SDK retries apply; reconcile uncertain state.
- Maintenance: new Checkout is blocked, while existing webhook/query paths remain usable.
- Queue unavailable: restore workers and retry durable provider events.

## Security

The verifier uses the official Stripe SDK with exact raw bytes, `Stripe-Signature`, endpoint-secret rotation, SDK timestamp tolerance, and constant-time comparison. Secrets are environment/account scoped. Card details never enter the application. Prompt 639 owns trust and replay decisions; Prompt 638 owns all financial mutations.

## Refund status

Refunds are intentionally disabled in Prompt 641. `PaymentCapability::Refund` is false and refund calls fail before any Stripe API request. A later audited enhancement can integrate Stripe refunds through the existing `BillingRefundService`.

References: [Checkout Sessions](https://docs.stripe.com/api/checkout/sessions), [webhooks](https://docs.stripe.com/webhooks), and [idempotent requests](https://docs.stripe.com/api/idempotent_requests).
