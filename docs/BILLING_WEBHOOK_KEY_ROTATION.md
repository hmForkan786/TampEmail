# Billing Webhook Key Rotation

Signing keys are provider- and environment-scoped. Database secrets use Laravel's encrypted cast and are hidden from serialization. `active` and time-valid `retiring` keys are candidates; `revoked`, expired, not-yet-valid, and environment-mismatched keys are excluded. Candidate lookup is bounded by `max_candidate_signing_keys`.

Rotation procedure:

1. Insert the new encrypted key as `active` with a distinct public key ID.
2. Mark the previous key `retiring` and set a short `valid_until` overlap.
3. Confirm callbacks match the new public key ID in safe audits.
4. Revoke the old key immediately after the overlap, or sooner during an incident.

Never put production secrets in source control, command arguments, logs, audit metadata, or tickets. The config-backed fake key exists for tests and controlled development only.
