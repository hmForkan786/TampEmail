# Billing Production Audit (Prompt 646)

Final production readiness audit of the billing subsystem built in Prompts 636–645. This document is verification only: **no new product features**.

**Audit date:** 2026-07-29  
**Branch:** `feature/billing-payments`  
**Baseline HEAD at audit start:** `a1926aa`

## Locked baseline

| Prompt | Status |
| --- | --- |
| 636 Billing Foundation | PASS |
| 637 Provider Checkout | PASS |
| 638 Payment Processing | PASS |
| 639 Verification | PASS |
| 640 SSLCommerz | PASS |
| 641 Stripe | PASS |
| 642 Manual Crypto | PASS |
| 643A bKash | SKIPPED |
| 643B Nagad | SKIPPED |
| 644 Renewal & Grace | PASS |
| 645 Invoice & Billing History | PASS |

## Frozen architecture (unchanged by this audit)

`PaymentProcessingService`, ledger (`payment_transactions`), `CheckoutService`, `BillingOrder`, Invoice services, `PaymentGateway` contract, Prompt 639 verification layer, `PaidSubscriptionActivationService`, commercial entitlement resolver.

## Architecture

| Check | Result |
| --- | --- |
| Provider-neutral boundaries | PASS — adapters under `Gateways/` / provider namespaces only |
| Single financial authority | PASS — `PaymentProcessingService` |
| Single activation authority | PASS — `PaidSubscriptionActivationService` |
| Ledger append-oriented | PASS — unique provider/tx + order idempotency keys |
| Invoice immutable lifecycle | PASS — state machine; paid cannot void |
| No duplicate settlement path | PASS — paid only after verified processing |

## Security

| Check | Result |
| --- | --- |
| Owner scoping (orders/invoices/payments) | PASS |
| Admin read-only invoices | PASS — `isPlatformAdmin()` |
| Cross-user denial | PASS — covered by checkout/invoice tests |
| Webhook signature / replay / timestamp | PASS — Prompt 639 boundary |
| Secrets via environment | PASS — no live provider secrets in code |
| Browser return non-authoritative | PASS — `BillingReturnController` → sync job only |

Production must keep `fake` out of `BILLING_ENABLED_GATEWAYS` unless intentionally used in non-prod.

## Financial integrity

```text
BillingOrder → verified payment → ledger → invoice → activation → subscription
```

| Check | Result |
| --- | --- |
| Amount/currency fail-closed | PASS |
| Unique invoice per order | PASS |
| Unique ledger keys | PASS |
| No cascade delete of financial rows | PASS — `restrictOnDelete` |
| Invoice ↔ ledger consistency | PASS — fail-closed on mismatch |

## Providers

| Provider | Checkout | Verify | Query | Refund | Verdict |
| --- | --- | --- | --- | --- | --- |
| Fake | Yes | Yes | Yes | Yes (test) | PASS (non-prod) |
| SSLCommerz | Yes | Yes (S2S) | Yes | No | PASS |
| Stripe | Yes | Yes | Yes | No | PASS |
| Manual Crypto | Yes | Internal approve path | Yes | No | PASS |
| bKash / Nagad | — | Fail-closed stubs | — | — | SKIPPED |

## Invoices

Immutable numbering (`INV-YYYY-######`), issued totals/line items, PDF regeneration with content fingerprint — PASS (Prompt 645).

## Renewal

Scheduled renewal orders, grace, expiry, recovery only via paid activation — PASS (Prompt 644). Overlap protection: `withoutOverlapping()` on lifecycle schedules.

## Operational readiness

See [BILLING_OPERATIONS_RUNBOOK.md](BILLING_OPERATIONS_RUNBOOK.md) and [BILLING_DEPLOYMENT_CHECKLIST.md](BILLING_DEPLOYMENT_CHECKLIST.md).

| Area | Result |
| --- | --- |
| Queues configured | PASS (defaults to `default`; env overrides available) |
| Scheduler commands registered | PASS |
| Health commands | PASS (`billing:stripe-health`, `billing:sslcommerz-health`) |
| Reconciliation command | PASS (manual `billing:reconcile`; not on schedule by design) |

## Monitoring

Audit actions for checkout, callbacks, payments, activation, invoices, manual crypto, reconciliation. Health commands refuse unsafe production debug unless explicitly enabled (`billing:webhook-verify`).

## Recovery

Documented in the operations runbook for: queue interruption, duplicate callbacks, provider outage, temporary DB outage, invoice regeneration, reconciliation rerun.

## Regression (audit execution)

| Suite | Result |
| --- | --- |
| Billing-focused filter | PASS — 121 passed, 3 skipped |
| Commercial / entitlement / API-key filter | PASS — 149 passed, 2 skipped |
| Full repository suite (clean caches) | Re-verified after route-cache race; see completion report |
| Provider regressions | Covered in billing-focused + targeted filters |

Expected skips: external SMTP sandbox, relational API-key concurrency harness, similar environment-gated tests.

## Static analysis

| Check | Classification |
| --- | --- |
| Pint (dirty paths) | PASS |
| PHPStan billing paths | PASS (0 errors) |
| Full PHPStan | Existing baseline — ~233 pre-existing errors outside Prompt 645/646 billing paths (KNOWN) |
| `git diff --check` | PASS |

## Documentation map

Architecture, checkout, processing, callbacks, settlements, sync, state machines, security, webhooks, invoices, renewal, Stripe, SSLCommerz, manual crypto, incident response, key rotation, this audit, ops runbook, deployment checklist.

## Known limitations (accepted)

- bKash / Nagad skipped
- Provider refunds not production-implemented (Fake only)
- No tax engine / ERP / multi-currency conversion / chargebacks product UI
- Manual crypto is human-review settlement, not chain confirmation
- Stripe is one-time Checkout, not Stripe Billing subscriptions
- Soft invoice immutability (state machine; no DB column freeze triggers)
- Full PHPStan repo baseline remains red with pre-existing debt

## Acceptance

No financial inconsistency, authorization bypass, provider boundary violation, or duplicate settlement path identified. Billing subsystem is **production-audited** subject to deployment checklist completion in the target environment.
