# Billing Payment Status Synchronization

Owner endpoint:

`POST /api/v1/billing/orders/{billingOrder}/sync`

Internal command:

`php artisan billing:sync-payment-status [--order=UUID] [--stale-minutes=10] [--limit=100] [--dry-run]`

Stale processing orders synchronize every five minutes when enabled. Provider
queries run outside database transactions, require query capability, and
validate order ID, amount, and currency before entering verified processing.
Repeated synchronization uses deterministic identity and creates no duplicate
financial effect.

Terminal orders are skipped. Query failures leave the ledger untouched and are
audited safely. The fake adapter returns success for references containing
`success`; other references remain pending for deterministic testing.
