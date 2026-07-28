# Billing Webhook Verification Adapters

Each adapter implements `ProviderWebhookVerifier` and owns header parsing, signature-version validation, canonicalization, cryptographic verification, and trusted event/nonce extraction. The registry rejects duplicate registrations, while the resolver normalizes and validates provider names without controller branching.

`fake` is the only operational adapter in Prompt 639. Stripe, SSLCommerz, bKash, and Nagad are explicit fail-closed stubs; their documented provider specifications must define signed bytes, encoding, timestamp semantics, key source, and acknowledgement policy before activation. Guess-based verification is forbidden.

The asymmetric foundation allowlists RSA-SHA256 and ECDSA-SHA256, safely rejects invalid PEM and algorithm downgrade, and exposes a public-key fingerprint. Future certificate retrieval must authenticate its source and validate certificate time and trust chains.
