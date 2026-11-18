# Billing Deployment Checklist

Production cutover checklist for the billing subsystem. Complete **before** enabling paid gateways for real customers.

## 1. Application environment

| Variable / setting | Required production value |
| --- | --- |
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| `APP_URL` | Canonical HTTPS origin |
| `QUEUE_CONNECTION` | Persistent driver (`database` / `redis` / SQS) — **not** `sync` for payment jobs |
| `CACHE_DRIVER` | Non-`array` for multi-process (prefer `redis`/`database`) |
| `SESSION_DRIVER` | Persistent |
| `LOG_CHANNEL` | Stack/production channel without secret echo |

## 2. Billing gateways

```env
BILLING_DEFAULT_GATEWAY=stripe   # or sslcommerz / manual_crypto — never fake in prod
BILLING_ENABLED_GATEWAYS=stripe,sslcommerz
```

- Remove `fake` from enabled gateways in production.
- Keep `BILLING_WEBHOOK_SECURITY_ENABLED=true`.

## 3. Provider secrets (examples)

### Stripe

- `STRIPE_*` publishable/secret/webhook secrets for the live (or intentionally test) mode.
- Mode mismatch must remain rejected (test/live fail-closed).

### SSLCommerz

- `SSLCOMMERZ_*` store id/password/hash, sandbox vs live endpoints.
- Multi-store: ensure store resolver credentials are complete per store.

### Manual crypto

- `MANUAL_CRYPTO_*` wallets, evidence limits, disks — no private keys in repo.

## 4. Lifecycle & invoices

```env
BILLING_GRACE_DAYS=7
BILLING_RENEWAL_LEAD_DAYS=3
BILLING_TRIAL_DAYS=14
BILLING_LIFECYCLE_BATCH_SIZE=100
BILLING_INVOICE_PREFIX=INV
BILLING_INVOICE_NUMBER_PADDING=6
```

Out-of-bounds lifecycle values fail closed when scheduled work runs.

## 5. Queues (recommended explicit)

```env
BILLING_QUEUE_PROVIDER_EVENTS=billing
BILLING_QUEUE_ACTIVATION=billing
BILLING_QUEUE_RECONCILIATION=billing
```

Supervisor / systemd / Horizon must run workers for these queues continuously.

## 6. Pre-deploy commands

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan migrate --force
php artisan billing:stripe-health      # if Stripe enabled
php artisan billing:sslcommerz-health  # if SSLCommerz enabled
php artisan schedule:list              # confirm billing schedules
```

## 7. Runtime processes

- [ ] Web (PHP-FPM / Octane / IIS) behind HTTPS
- [ ] Queue worker(s) for billing queues
- [ ] Scheduler (`schedule:run` every minute)
- [ ] Log shipping / disk space monitoring

## 8. Callback & return URLs

- Provider IPN/webhook URLs point to `/api/v1/billing/providers/{provider}/callback`
- Signed browser return `/billing/return/{provider}` is navigation-only
- Allowed redirect hosts configured (`BILLING_ALLOWED_REDIRECT_HOSTS` / app URL)

## 9. Database

- [ ] Migrations applied through invoice tables (`billing_invoices`, sequences, credit notes)
- [ ] Backups enabled (logical dump + binlog/PITR as applicable)
- [ ] Rollback plan: revert release artifact; **do not** drop financial tables to “undo” payments

## 10. Smoke tests after deploy

1. Start checkout on each enabled gateway (small amount / test mode if required).
2. Confirm webhook accepted (`202` / provider ACK) and order → paid → invoice → activation.
3. Confirm owner history endpoints and PDF download.
4. Confirm cross-user order/invoice access returns 404.
5. Confirm failed signature is rejected (401/503 as designed).

## 11. Rollback procedure

1. Disable new checkouts (`BILLING_ENABLED_GATEWAYS=` empty or maintenance flags).
2. Keep workers running until in-flight provider events drain.
3. Redeploy previous known-good artifact.
4. Do not reverse ledger/invoice rows; use credit-note foundation / future refunds for commercial corrections.

## 12. Backup strategy

- Daily (or continuous) backup of MySQL including `billing_*`, `payment_*`, `audit_logs`.
- Retain backups beyond longest dispute window with payment providers.
- Test restore quarterly on a non-prod clone.

## Acceptance gate

Deployment is ready only when sections 1–11 are checked for the target environment and smoke tests pass for every enabled provider.
