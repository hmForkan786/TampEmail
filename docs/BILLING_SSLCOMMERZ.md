# SSLCommerz Billing Integration

## Architecture

SSLCommerz is a `PaymentGateway` adapter, not a separate billing system:

```text
CheckoutService → SslCommerzPaymentGateway → hosted GatewayPageURL
browser return → SyncPaymentStatusJob → queryPayment()
form IPN → raw capture → Prompt 639 verifier → Validation API
         → Prompt 638 ingestion/ledger → activation job
```

The browser success, fail, and cancel URLs are non-authoritative. Every return queues status synchronization and redirects with `pending_verification`. Only a server-to-server Validation API response can establish payment truth.

## Sandbox setup

Create a sandbox store through the [official registration page](https://developer.sslcommerz.com/registration/), then configure:

```dotenv
SSLCOMMERZ_ENABLED=true
SSLCOMMERZ_ENV=sandbox
SSLCOMMERZ_DEFAULT_STORE=default
SSLCOMMERZ_DEFAULT_STORE_ENV=sandbox
SSLCOMMERZ_STORE_ID=your-sandbox-store-id
SSLCOMMERZ_STORE_PASSWORD=your-sandbox-password
SSLCOMMERZ_SUPPORT_PHONE=your-merchant-support-number
BILLING_ENABLED_GATEWAYS=fake,sslcommerz
```

The application posts session initialization to `https://sandbox.sslcommerz.com/gwprocess/v4/api.php`. Configure the IPN URL as:

```text
https://your-host.example/api/v1/billing/providers/sslcommerz/callback
```

Local automated tests use `Http::fake()` and never contact SSLCommerz or contain personal credentials.

## Production deployment

Use a separate production store and set `SSLCOMMERZ_ENV=production`. Production resolves only `https://securepay.sslcommerz.com`; it never falls back to sandbox credentials. The application and every return/IPN URL must be public HTTPS. Run `php artisan config:cache`, keep the billing/default queue worker active, and keep scheduled payment synchronization running.

After deployment:

1. Verify outbound TLS 1.2+ access.
2. Run `php artisan billing:sslcommerz-health`.
3. Register the exact IPN URL in the merchant panel.
4. Execute a low-value production transaction and confirm validation, ledger processing, and one activation.

## Multi-store and credential rotation

Stores are isolated under `billing.sslcommerz.stores`. The persisted order metadata records only the selected store key, environment, and provider transaction reference. Credentials are never persisted or returned by the store value object.

To rotate credentials, add or update the bounded store configuration, deploy the secret through the environment manager, rebuild config cache, run the health check, and then revoke the old merchant credential. Never permit callback fields to select credentials or endpoints.

## Validation and replay behavior

The form-urlencoded IPN parser rejects duplicate keys, bracket/nested syntax, malformed percent encoding, excessive fields, and oversized values while preserving the original bytes separately. `val_id`, `tran_id`, amount, currency, status, selected store, and internal order mapping are validated server-to-server. The validated `val_id` is used by Prompt 639 nonce protection; exact retries are acknowledged idempotently and conflicting replays are rejected.

## Maintenance and outages

`SSLCOMMERZ_MAINTENANCE_MODE=true` blocks new checkout initialization without changing existing financial state. Transport calls have bounded connect/request timeouts and retries. An uncertain or pending transaction remains processing and is recovered through existing manual or scheduled synchronization.

Safe audit records may contain provider, order/request identifiers, store key, environment, result category, and payload hashes. They must never contain store passwords, credential-bearing URLs, raw provider responses, or customer payment data.

## Troubleshooting

- `GatewayPageURL` missing: inspect safe provider status and required checkout fields.
- Invalid credentials: confirm store/environment pairing and rebuild config cache.
- Validation failure: compare `tran_id`, `val_id`, amount, currency, and selected store.
- Duplicate transaction/session: reuse the original checkout idempotency key and reconcile; do not create unlimited sessions.
- IPN not received: verify public HTTPS reachability and merchant-panel IPN registration.
- Return received but pending: ensure queue workers can execute `SyncPaymentStatusJob`.
- Amount/currency mismatch: preserve the order snapshot and investigate; never round or override it.
- Provider timeout/maintenance: leave the order non-paid and retry through bounded synchronization.
- Activation delayed: inspect the activation queue after the payment provider event is processed.
- Wrong sandbox/live credentials: correct the environment-scoped store; no cross-environment fallback exists.

## Refund status

Refund is intentionally unsupported in Prompt 640. `PaymentCapability::Refund` is `false`, and refund calls fail without provider HTTP traffic. A future audited implementation may enable it through the existing `BillingRefundService`.

Protocol reference: [SSLCommerz API v4](https://developer.sslcommerz.com/doc/v4/).
