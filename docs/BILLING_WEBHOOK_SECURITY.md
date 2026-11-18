# Billing Webhook Security

Provider callbacks cross an unauthenticated network trust boundary. The controller captures the exact request bytes, bounds body size and content type, then invokes `ProviderWebhookVerificationService`. Only a verified result can enter the Prompt 638 ingestion pipeline. Verification failures create a safe audit signal but never a processable provider event or financial mutation.

The fake adapter requires `X-Fake-Signature: v1=<lowercase hex HMAC>`, `X-Fake-Timestamp`, and `X-Fake-Nonce`. Its signed bytes are exactly `timestamp.nonce.rawBody`; JSON is never decoded and re-encoded before verification. SHA-256 and SHA-512 are allowlisted, signatures are strictly decoded, and `hash_equals` performs comparison. MD5, SHA-1, malformed encodings, missing headers, unknown versions, disabled providers, and unconfigured adapters fail closed.

Timestamp validation uses UTC, a bounded future skew, and a replay window. Nonces are stored only as keyed hashes under a database uniqueness constraint. A duplicate nonce with the same payload fingerprint is a legitimate exact retry and continues into existing event idempotency. A duplicate nonce with different bytes is rejected as a suspicious replay.

Payloads and canonical bytes are not logged. Audits contain request IDs, provider, bounded failure codes, hashes, and public key IDs only. Source IP is supplemental and optionally allowlisted; it is not proof of authenticity. Production callbacks require HTTPS at the edge.

Run `billing:prune-webhook-security --dry-run` to inspect expired nonce pruning. The scheduled command deletes only expired, non-financial replay records. `billing:webhook-verify` is local/staging diagnostics, emits only PASS/FAIL, and is disabled in production by default.
