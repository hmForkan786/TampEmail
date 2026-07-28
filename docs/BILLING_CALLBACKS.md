# Billing Provider Callbacks

`POST /api/v1/billing/providers/{provider}/callback` accepts bounded JSON,
resolves an enabled gateway, verifies through the gateway contract, redacts
sensitive fields, fingerprints the payload, and persists the event before
dispatching its unique processing job.

Exact replay is acknowledged. Reuse of an event ID with a different payload hash
is rejected and audited. Unknown/disabled providers, invalid signatures,
malformed JSON, unsupported content types, and oversized payloads fail closed.
Acknowledgement does not claim financial processing has completed.

The signed browser return may enqueue a status query and redirects to
`pending_verification`; it cannot mutate an order, ledger, session, or
subscription. Future adapters supply provider-specific timestamp/signature
verification. PAN, CVV, PIN, OTP, credentials, tokens, and raw secrets are not
persisted.
# Security trust boundary

All provider callbacks are verified against their exact raw request bytes before this ingestion flow. See [BILLING_WEBHOOK_SECURITY.md](BILLING_WEBHOOK_SECURITY.md) for signature, timestamp, nonce, acknowledgement, and retention policies.
