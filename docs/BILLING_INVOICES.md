# Billing invoices and history

Prompt 645 adds provider-neutral invoices, payment history, PDF downloads, and a credit-note foundation. Invoices are **records only** — they are never payment authority.

## Architecture

```text
VerifiedProviderEvent
        ↓
PaymentProcessingService (Prompt 638)
        ↓
InvoiceService::issuePaidFromOrder
        ↓
draft → issued → paid
```

Browser returns, client requests, and admin buttons cannot mark an invoice paid.

## Numbering

Format: `{PREFIX}-{YEAR}-{SEQUENCE}` e.g. `INV-2026-000001`.

```env
BILLING_INVOICE_PREFIX=INV
BILLING_INVOICE_NUMBER_PADDING=6
```

Numbers are unique, immutable, and never reused. Allocation locks `billing_invoice_sequences` per year.

## Generation rules

Invoices are created only after a verified payment succeeds for:

- Initial purchase
- Renewal
- Manual recovery renewal (`metadata.recovery = true` on the renewal order)

Never for failed payments, cancelled checkouts, or abandoned checkouts.

One invoice per `billing_order_id`. Totals are copied from the order snapshot (`*_minor`) and never edited after issue.

## Ledger consistency

Before issue/paid, invoice totals must equal succeeded sale/capture ledger totals for the order. Mismatch fails closed.

## Owner API

| Method | Path |
|--------|------|
| GET | `/api/v1/billing/invoices` |
| GET | `/api/v1/billing/invoices/{id}` |
| GET | `/api/v1/billing/invoices/{id}/download` |
| GET | `/api/v1/billing/invoices/export` |
| GET | `/api/v1/billing/payments` |
| GET | `/api/v1/billing/orders` |

Cursor pagination (`cursor`, `per_page`). Search filters: `invoice_number`, `status`, `provider`, `subscription_id`, `from`, `to`.

Admin read-only mirrors under `/api/v1/admin/billing/invoices`.

## PDF

Generated only for `issued` or `paid` invoices via Dompdf. Content is fingerprinted; regeneration must match. No internal IDs or secrets.

## Credit notes

Foundation only (`draft` / `issued`). No refund integration and no ledger mutation.

## Void

Allowed for unpaid invoices (`draft` / `issued`). Never `paid → void`.

## Audit actions

`invoice_created`, `invoice_issued`, `invoice_paid`, `invoice_voided`, `invoice_downloaded`.
