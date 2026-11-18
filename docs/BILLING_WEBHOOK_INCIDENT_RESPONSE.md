# Billing Webhook Incident Response

For invalid-signature or replay anomalies: disable the affected provider callback, preserve safe audit identifiers and payload hashes, revoke the suspected key, rotate credentials at the provider and application, and temporarily narrow the source-network policy if authoritative ranges exist. Inspect bounded metrics and verified provider events; never treat an unverified raw callback as trusted.

Restore service only after a fixed-vector verification succeeds with the new key. Reprocess the already verified provider event through the existing Prompt 638 job; do not create a second financial path. Escalate repeated nonce conflicts, multiple-key matches, timestamp drift, or unexpected source networks.
