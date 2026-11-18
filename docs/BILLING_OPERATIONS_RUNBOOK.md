# Billing Operations Runbook

Operational procedures for the production billing subsystem (Prompts 636–645). Companion to [BILLING_PRODUCTION_AUDIT.md](BILLING_PRODUCTION_AUDIT.md).

## Financial authority reminder

```text
Provider callback / status sync / manual-crypto approve
        ↓
Prompt 639 verification (where HTTP)
        ↓
PaymentProcessingService (only financial mutation path)
        ↓
Invoice + ActivatePaidSubscriptionJob
```

Never mark orders paid from admin UI, browser return, or ad-hoc SQL.

## Workers & queues

| Queue config key | Env | Default |
| --- | --- | --- |
| `billing.queues.provider_events` | `BILLING_QUEUE_PROVIDER_EVENTS` | `default` |
| `billing.queues.activation` | `BILLING_QUEUE_ACTIVATION` | `default` |
| `billing.queues.reconciliation` | `BILLING_QUEUE_RECONCILIATION` | `default` |

Run at least one queue worker that consumes these queues (often a single `default` worker in smaller deployments):

```bash
php artisan queue:work --queue=default --tries=3 --timeout=120
```

Critical jobs (idempotent / unique where applicable):

- `ProcessPaymentProviderEventJob`
- `ActivatePaidSubscriptionJob` (`ShouldBeUnique` per order)
- `SyncPaymentStatusJob`
- Lifecycle jobs (renewal / grace / expire)

## Scheduler

Ensure `php artisan schedule:run` executes every minute (cron / Task Scheduler / systemd timer).

| Cadence | Command |
| --- | --- |
| Every 5 minutes | `billing:create-renewal-orders` |
| Every 5 minutes | `billing:start-grace-periods` |
| Every 5 minutes | `billing:expire-lifecycle-subscriptions` |
| Every 5 minutes | `subscriptions:expire` (safety net) |
| Every 5 minutes | `billing:expire-checkouts` |
| Every 5 minutes | `billing:sync-payment-status` (when payment sync enabled) |
| Daily | `billing:prune-webhook-security` |

All listed billing schedules use `withoutOverlapping()`.

Manual (not scheduled):

```bash
php artisan billing:reconcile
php artisan billing:stripe-health
php artisan billing:sslcommerz-health
php artisan billing:sync-payment-status --order=<uuid>   # if supported
```

## Health checks

1. `php artisan billing:stripe-health` — account/config reachability (safe output).
2. `php artisan billing:sslcommerz-health` — store/TLS readiness (safe output).
3. Confirm queue depth not unbounded; failed jobs inspected for billing queues.
4. Confirm scheduler heartbeat / last `schedule:run`.
5. Spot-check recent `AuditLog` actions: `billing.payment.succeeded`, `billing.subscription.activated`, `invoice_paid`.

## Disaster recovery playbooks

### Queue interruption

1. Restore workers.
2. Do **not** replay provider webhooks blindly first — check `payment_provider_events` for received/unprocessed rows.
3. Re-dispatch or allow `ProcessPaymentProviderEventJob` retry for stuck events.
4. For stale processing orders, use `billing:sync-payment-status` / reconciliation.

### Duplicate callbacks

Expected: unique `(provider, provider_event_id)`, payload hash conflict audits, webhook nonce replay protection. Duplicate success must not double-charge ledger or activation (`ShouldBeUnique` + paid short-circuit).

### Provider outage

1. Checkout will fail closed for that gateway — leave order pending/processing.
2. When provider recovers, browser return / sync / IPN can complete via existing pipeline.
3. Enable maintenance flags for Stripe/SSLCommerz if configured to refuse new checkouts during incident.

### Temporary database outage

1. Fail closed — do not accept unverified side-channel payment claims.
2. After DB recovery, run migrations if needed, then queue workers, then `billing:reconcile` and status sync for stale orders.

### Invoice regeneration

PDF may be regenerated for issued/paid invoices. Content fingerprint must match. If fingerprint fails, investigate DB mutation — never “fix” by editing issued totals.

### Reconciliation rerun

```bash
php artisan billing:reconcile
```

Safe to re-run; anomalies are detected and recovery work enqueued according to existing reconciliation service. Does not invent payments.

### Manual crypto stuck claims

Use admin review APIs only (`approve` / `reject` / `reopen`). Approval must flow through `PaymentCallbackIngestionService` → processing pipeline.

## Secret & logging rules

- Store provider secrets only in environment / secret manager.
- Never log raw webhook bodies with secrets, API keys, store passwords, or wallet private material.
- API responses must stay redacted (no `provider_secret`, fees internals where tests assert absence).

## Incident escalation order

1. Confirm whether money movement occurred at the provider.
2. Confirm internal order/ledger/invoice/activation state.
3. Prefer provider query + `PaymentStatusSynchronizationService` over manual SQL.
4. If ledger and provider disagree, open reconciliation — do not force `paid` in DB.
