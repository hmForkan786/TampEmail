# Temail documentation index

This index separates current product and operational contracts from historical
implementation records. The root [`README.md`](../README.md) is the product
overview and setup entry point; the documents below provide the detailed
contracts.

## 1. Getting started

- [`LOCAL_DEVELOPMENT.md`](LOCAL_DEVELOPMENT.md) — repository-local setup and safe development defaults
- [`ARCHITECTURE.md`](ARCHITECTURE.md) — system boundaries and major runtime dependencies

## 2. API contracts

- [`API_REFERENCE.md`](API_REFERENCE.md) — authoritative owner API and signed webhook reference
- [`API_CONVENTION.md`](API_CONVENTION.md) — shared prefixes, authentication, ownership, errors, and limits
- [`INBOUND_ROUTING_CONTRACT.md`](INBOUND_ROUTING_CONTRACT.md) — inbound webhook routing and deferred SMTP/LMTP boundaries
- [`INBOX_LIFETIME_POLICY.md`](INBOX_LIFETIME_POLICY.md) — expiration and owner renewal contract

## 3. Architecture and security

- [`PLATFORM_OPERATOR_POLICY.md`](PLATFORM_OPERATOR_POLICY.md) — operator/admin capability boundary and fail-closed policy
- [`MAIL_SERVER_OWNERSHIP_POLICY.md`](MAIL_SERVER_OWNERSHIP_POLICY.md) — platform-managed MailServer ownership
- [`ANONYMOUS_MAIL_SERVER_POOL.md`](ANONYMOUS_MAIL_SERVER_POOL.md) — anonymous provisioning pool policy
- [`RELATIONAL_CONCURRENCY_PROTOCOL.md`](RELATIONAL_CONCURRENCY_PROTOCOL.md) — relational concurrency test protocol
- [`RELATIONAL_TEST_MATRIX.md`](RELATIONAL_TEST_MATRIX.md) — supported concurrency verification matrix

## 4. Inbound and attachment processing

- [`ATTACHMENT_SCANNING_CONTRACT.md`](ATTACHMENT_SCANNING_CONTRACT.md) — scanner lifecycle and fail-closed safety contract
- [`CLAMAV_INTEGRATION_TESTING.md`](CLAMAV_INTEGRATION_TESTING.md) — local and CI ClamAV verification
- [`INBOUND_RETENTION_POLICY.md`](INBOUND_RETENTION_POLICY.md) — inbound email and attachment retention

## 5. Operations and production

- [`PRODUCTION_RUNBOOK.md`](PRODUCTION_RUNBOOK.md) — deployment, health, incident, and enablement guidance
- [`PROCESS_OPERATIONS.md`](PROCESS_OPERATIONS.md) — queue worker and scheduler operations
- [`PROCESS_RUNTIME_VERIFICATION.md`](PROCESS_RUNTIME_VERIFICATION.md) — isolated process readiness verification
- [`PRODUCTION_READINESS.md`](PRODUCTION_READINESS.md) — readiness evidence and limitations
- [`LOG_RETENTION_POLICY.md`](LOG_RETENTION_POLICY.md) — API request and audit-log retention

## 6. Policies

- [`MAIL_SERVER_OWNERSHIP_POLICY.md`](MAIL_SERVER_OWNERSHIP_POLICY.md)
- [`PLATFORM_OPERATOR_POLICY.md`](PLATFORM_OPERATOR_POLICY.md)
- [`INBOUND_RETENTION_POLICY.md`](INBOUND_RETENTION_POLICY.md)
- [`LOG_RETENTION_POLICY.md`](LOG_RETENTION_POLICY.md)
- [`INBOX_LIFETIME_POLICY.md`](INBOX_LIFETIME_POLICY.md)

## 7. Historical implementation records

Files ending in `_CHANGE_MANIFEST.md` record earlier implementation phases,
staging boundaries, and audit decisions. They are not current API, product,
deployment, or operational contracts. Current documents in this index and the
root README take precedence.

- [`ATTACHMENT_DOWNLOAD_API_CHANGE_MANIFEST.md`](ATTACHMENT_DOWNLOAD_API_CHANGE_MANIFEST.md)
- [`EMAIL_READ_STATE_CHANGE_MANIFEST.md`](EMAIL_READ_STATE_CHANGE_MANIFEST.md)
- [`INBOX_API_CHANGE_MANIFEST.md`](INBOX_API_CHANGE_MANIFEST.md)
- [`INBOX_LIFECYCLE_CHANGE_MANIFEST.md`](INBOX_LIFECYCLE_CHANGE_MANIFEST.md)
- [`PROCESS_OPERATIONS_CHANGE_MANIFEST.md`](PROCESS_OPERATIONS_CHANGE_MANIFEST.md)
