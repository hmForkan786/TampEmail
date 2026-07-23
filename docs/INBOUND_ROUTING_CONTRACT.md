# Inbound Recipient Routing Contract

Status: the signed provider-neutral webhook boundary, queued parsing/persistence, recipient resolution, and failure/replay flow are implemented. Native SMTP/LMTP ingress and transport acknowledgement remain deferred.

## Implemented boundary

The implemented inbound boundary is `POST /api/v1/inbound/webhook`. It verifies the signed provider headers, validates a bounded canonical envelope, applies duplicate/idempotency handling, queues parsing and persistence, resolves the target domain and inbox, and records safe processing/failure state. Replay is an authorized operational action and follows the same bounded processing and scanning lifecycle.

## Current schema evidence

- `domains.domain` is a unique string up to 255 characters and domains support soft deletion, `is_active`, `is_public`, `allow_registration`, and `is_healthy`.
- `inboxes` stores `domain_id`, nullable `user_id`, `local_part`, unique `full_address`, `is_active`, nullable `expires_at`, and soft deletion.
- `Inbox` exposes `active()` and `expired()` scopes; `Domain` exposes `active()`, `publiclyVisible()`, and `registrationAllowed()` scopes.
- `CreateInboxAction` uses `public_mail_server_pool` only for anonymous provisioning. That pool-selection path is not an inbound recipient resolver.

## Canonical address format

The canonical routing key is the complete mailbox address:

```text
normalized-local-part@normalized-domain
```

The resolver must parse exactly one address using a standards-compliant mailbox parser. It must reject empty input, multiple addresses, comments, display names unless explicitly extracted by the ingress adapter, control characters, CR/LF, surrounding or internal invalid whitespace, and malformed local/domain syntax.

The database currently permits `full_address` up to 255 characters and `local_part` up to 120 characters. The ingress contract must reject addresses exceeding those limits before querying. It must not silently truncate.

## Normalization

Normalization must be deterministic and idempotent:

1. Trim only permitted outer transport whitespace after rejecting control characters.
2. Split at the single mailbox separator into local part and domain.
3. Lowercase and IDNA-normalize the domain to its canonical ASCII form. Store and compare the normalized domain representation; Unicode display form is not a routing key.
4. Preserve local-part case by default because SMTP local-part semantics are case-sensitive. The product may deliberately choose case-insensitive local parts, but that decision must be made before implementation and applied consistently to creation, lookup, uniqueness, and display. This repository currently has no documented local-part normalization convention.
5. Do not Unicode-normalize or case-fold the local part implicitly. Reject unsupported/confusable characters according to the selected mailbox parser policy.

Normalization must never use `pool_key`, hostname, provider, or a fallback address. No arbitrary server selection is allowed.

## Resolution order

The resolver executes this order:

1. Parse and validate the recipient address.
2. Normalize the address and derive the normalized domain.
3. Resolve the exact domain by normalized domain value, excluding soft-deleted rows.
4. Require `domains.is_active=true`. For anonymous/public ingress also require `is_public=true`; `allow_registration` controls provisioning and must not by itself grant delivery access.
5. Apply the configured health policy. Recommended default is to reject unhealthy domains for new delivery while allowing an explicitly configured quarantine/retry state; this is not currently implemented.
6. Resolve the exact `inboxes.full_address` using the canonical normalized address, excluding soft-deleted rows.
7. Require `inboxes.is_active=true` and reject `expires_at <= now()`.
8. Return the resolved Inbox, Domain, owner (`user_id` nullable), and delivery policy. No MailServer pool selection occurs at this stage.

The `full_address` unique constraint is the current duplicate-address safeguard. Because database collation and case behaviour differ across SQLite, MySQL, and PostgreSQL, the resolver and creation path must use the same canonical representation; the existing schema alone does not prove case-insensitive uniqueness.

## Result contract

| Result | Internal code | Provider/SMTP mapping | Retry? | Processing event | Rejection log |
|---|---|---|---|---|---|
| Resolved | `resolved` | Accept/continue: SMTP `250` or provider success | No | `received` after acceptance | No rejection |
| Expired | `expired` | SMTP `550` permanent recipient failure | No | `rejected` | Yes, safe address hash/domain only |
| UnknownDomain | `unknown_domain` | SMTP `550` | No | `rejected` | Yes |
| InactiveDomain | `inactive_domain` | SMTP `550` unless policy explicitly quarantines | Usually no | `rejected` or `deferred` | Yes |
| UnknownInbox | `unknown_inbox` | SMTP `550` | No | `rejected` | Yes |
| InactiveInbox | `inactive_inbox` | SMTP `550` | No | `rejected` | Yes |
| InvalidAddress | `invalid_address` | SMTP `553` or provider validation failure | No | `rejected` | Yes, without raw address |
| PublicIngressDisabled | `public_ingress_disabled` | SMTP `550` or provider policy failure | No | `rejected` | Yes |

Temporary infrastructure/database/provider failures are not one of the recipient results. They must produce a deferred/retry outcome, never `UnknownInbox`, and must not be acknowledged as successful delivery.

Provider-specific status codes may wrap these internal codes, but the internal code must remain stable. Rejection logs must contain only operational-safe identifiers such as a one-way address digest, normalized domain ID, result code, source, and timestamp. Raw recipient, message body, headers, credentials, and provider payloads must not be logged.

## Ownership and public handling

An Inbox with a non-null `user_id` is user-owned. Resolution must return the owner for authorization and event attribution, but inbound delivery must not infer ownership from the sender or header values.

An Inbox with a null `user_id` is anonymous/public only when its resolved Domain satisfies the public ingress policy. Public status is a domain policy, not a reason to fall back to another domain or `pool_key=null`. Unknown or inactive public configuration fails closed.

The `PUBLIC_MAIL_SERVER_POOL` setting belongs to anonymous Inbox provisioning. It must not be used to resolve an incoming recipient address.

## Security requirements

- Trust only the envelope recipient supplied by the verified SMTP/provider boundary; treat `To`, `Cc`, and arbitrary message headers as untrusted metadata.
- Reject CR/LF and control characters before parsing or logging.
- Never route by display name, sender address, hostname, provider, or pool key.
- Never fall back from an unknown domain/inbox to a public pool.
- Keep normalization idempotent and use one canonical key for creation and lookup.
- Do not expose whether a private user-owned inbox exists to unauthenticated senders beyond the configured SMTP/provider rejection class.
- Apply message size, recipient count, rate, and abuse controls at the ingress boundary; none are currently implemented.

## Deferred SMTP/LMTP material

The result table above preserves the non-authoritative mapping vocabulary for a future SMTP or LMTP adapter. It does not imply that this repository currently runs an SMTP server, accepts LMTP traffic, performs SMTP routing, or operates a mail exchanger. Native SMTP ingress, LMTP ingress, SMTP server routing/mapping, and mail-exchanger operation remain deferred.

## Contract boundaries

1. Add a pure address parser/normalizer with explicit IDN and local-part policy.
2. Add a repository/service resolver implementing the lookup order and result enum above.
3. Add deterministic tests for invalid input, normalization idempotence, domain/inbox state, expiry, soft deletion, ownership, public policy, and duplicate addresses across supported databases.
4. Keep SMTP/provider acknowledgement outside the resolver; map resolver results in the ingress adapter.
5. Add safe rejection event/log writing without raw recipient/message data.
6. Add an explicit health/deferred policy and ensure infrastructure failures retry rather than become permanent recipient failures.

Native SMTP connection, migration, or existing-data normalization is outside this contract. The implemented webhook and queued processing paths are covered by the committed routes, services, jobs, and focused tests.

The signed provider-neutral boundary authenticates `X-Inbound-Provider`, `X-Inbound-Timestamp`, `X-Inbound-Signature`, and `X-Inbound-Message-Id` using provider-specific HMAC secrets. It validates a bounded canonical JSON envelope and returns `202`; queued processing performs MIME parsing, sanitization, resolver integration, and transactional persistence. Attachment scanning, retention, and authorized replay are separate committed lifecycle components.
