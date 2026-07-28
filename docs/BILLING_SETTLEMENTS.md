# Billing Settlements

Settlement is provider remittance state, separate from payment capture and the
ledger. Missing settlement capability never blocks verified payment.

Settlement states are pending, processing, settled, failed, reversed, and
unknown. Transitions are centralized. References are unique when present and
financial foreign keys restrict deletion. Gross/currency must match the payment
transaction; when fee, tax, and net are all supplied, their sum must equal
gross.

Public responses expose only settlement status, never fee/account data, failure
internals, or provider metadata. Duplicate references are idempotent and
conflicting transaction ownership fails closed.
