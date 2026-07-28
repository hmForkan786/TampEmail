# Billing Payment Processing

Prompt 638 extends the existing append-only payment ledger. Verified events are
normalized to pending, authorized, captured, succeeded, failed, cancelled,
expired, refund, chargeback, or reversal semantics. Browser returns remain
navigation only.

Authorization keeps the order processing and never activates a subscription.
Capture is cumulative, cannot exceed authorized or payable amounts, and pays
only after full capture. Direct sale requires the full server snapshot. Failed
and stale events append attempts without downgrading a paid order.

Public payment state is derived from the ledger. Reconciliation detects drift
against operational order state. Full payment completes checkout sessions and
dispatches the existing unique activation job after commit; activation failure
never rolls back financial truth.
