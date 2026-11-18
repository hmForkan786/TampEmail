# Mail Infrastructure Production Audit (Prompt 651)

Final production readiness audit of the **app-orchestrated mail architecture**. This document is verification only: **no new mail features, MTA, or infrastructure**.

**Audit date:** 2026-07-29  
**Branch:** `feature/billing-payments`  
**Baseline HEAD at audit start:** `ccaecaf`

## Locked baseline

| Item | Status |
| --- | --- |
| Billing Phase (636–646) | PASS |
| Inbound boundary | Signed webhook (`POST /api/v1/inbound/webhook`) |
| Outbound boundary | External SMTP via dedicated mailer |
| Processing | Queue-backed Laravel jobs |
| DNS-authenticated sending | SPF / DKIM / DMARC via `OutboundDomainAuthenticationService` |
| Native SMTP receiver | Not implemented (deferred) |
| Postfix / Exim orchestration | Not implemented (deferred) |

## Frozen architecture (unchanged by this audit)

Do **not** introduce Postfix, Exim, Dovecot, LMTP, native SMTP listener, MX receiver, SMTP proxy, Docker mail stack, or new infrastructure services under Prompt 651.

```text
Mail Provider
        │
        ▼
Signed Inbound Webhook
        │
        ▼
Inbound Queue (ProcessInboundMessageJob)
        │
        ▼
Parse → Resolve → Ingest / Storage
        │
        ▼
Inbox + optional attachment scanning

Application
        │
        ▼
Outbound Queue (DeliverOutboundMessageJob)
        │
        ▼
External SMTP (OutboundTransportInterface)
        │
        ▼
Recipient
```

## Architecture

| Check | Result |
| --- | --- |
| Inbound is signed webhook only | PASS — `InboundWebhookController`; contract in `docs/INBOUND_ROUTING_CONTRACT.md` |
| Outbound is external SMTP / provider events | PASS — `OutboundTransportInterface` + `DeliverOutboundMessageJob` |
| No native SMTP/LMTP ingress in routes | PASS |
| MailServer / MailProvider are inventory | PASS — pool/assignment abstraction; not an MTA control plane |
| Queue-backed inbound + outbound | PASS — `ShouldQueue` jobs with tries/backoff |

## Inbound

| Check | Result |
| --- | --- |
| Signed webhook endpoint | PASS — HMAC-SHA256 over `provider.timestamp.raw` |
| Timestamp skew / replay window | PASS — `inbound.timestamp_skew_seconds` (default 300) |
| Provider secret from env | PASS — `INBOUND_GENERIC_WEBHOOK_SECRET` / `config/inbound.php` |
| Rate limit | PASS — per provider+IP |
| Queue dispatch | PASS — `QueuedInboundWebhookDispatcher` → `ProcessInboundMessageJob` |
| Retry / backoff | PASS — 3 tries; `[60, 300, 900]` |
| Duplicate protection | PASS — `message_id` lookup before create + metrics `duplicate` |
| Ownership / recipient resolution | PASS — `InboundRecipientResolver` (active domain/inbox, expiry) |
| Failure + admin replay | PASS — `InboundFailureService` / `ReplayInboundFailureAction` (platform admin) |
| Health | PASS — `inbound:health` |
| Native SMTP/MX | **Deferred by design** — not a missing feature |

Inbound named workloads (`QUEUE_MAIL_INGESTION` / parsing / storage) exist in `config/queue.php`. `ProcessInboundMessageJob` currently uses the connection default queue; attachment scanning uses `attachment-scanning`. Deployments must include the default (or remapped) queue on an inbound-capable worker. See Known limitations.

## Outbound

| Check | Result |
| --- | --- |
| Transport abstraction | PASS — `OutboundTransportInterface` / manager |
| TLS / config validation | PASS — `OutboundTransportConfigValidator` (`encryption`, `verify_peer`, auth) |
| Queue-backed sending | PASS — `outbound-delivery` queue; `ShouldBeUnique` per message |
| Retry policy | PASS — `OUTBOUND_SEND_MAX_ATTEMPTS` + backoff list |
| Readiness checks | PASS — `outbound:launch-readiness`, `outbound:status`, queue readiness service |
| Suppression | PASS — `OutboundSuppressionService` |
| Sender profiles | PASS — `OutboundSenderProfileService` |
| Domain authorization dependency | PASS — enforced via `OutboundAuthorizationService` + domain auth |
| Kill switches / rollout | PASS — enable flags, rollout mode, emergency stop |

## DNS authentication

| Check | Result |
| --- | --- |
| Architecture expects authenticated domains | PASS — `OUTBOUND_DOMAIN_AUTH_ENFORCE` (default true) |
| SPF / DKIM / DMARC verification service | PASS — `OutboundDomainAuthenticationService` |
| Scheduled re-verify | PASS — `outbound:verify-domains` hourly (`withoutOverlapping`) |
| Documentation | PASS — outbound contract, launch runbook, `.env.example` |
| Live DNS probing in this audit | Not required |

**Operational requirement (production):** every outbound-enabled domain must present valid SPF, DKIM (provider-dependent), and DMARC (degraded DMARC policy configurable) before send is allowed when enforcement is on. Operators complete DNS at the registrar/provider; the app only verifies via DNS lookups and stores verification state.

## Queues

| Workload config key | Default queue name | Used by |
| --- | --- | --- |
| `mail_ingestion` / parsing / storage | `mail-ingestion` etc. | Reserved / foundation naming |
| `attachment_scanning` | `attachment-scanning` | `ScanInboundAttachmentJob` |
| `outbound_delivery` | `outbound-delivery` | `DeliverOutboundMessageJob` |
| `outbound_events` | `outbound-events` | `ProcessOutboundProviderEventJob` |
| `outbound_maintenance` | `outbound-maintenance` | Outbound maintenance workers |
| `notifications` | `notifications` | `SendOutboundNotificationEmailJob` |
| Billing queues | `default` (overrideable) | Billing jobs (reference only) |

| Check | Result |
| --- | --- |
| Retry + backoff on mail jobs | PASS |
| Idempotency / uniqueness where required | PASS — outbound unique job; inbound message_id; billing ledger keys |
| Failure handling | PASS — failed_jobs + inbound failure records + outbound failure codes |
| Supervisor examples | PASS — `deploy/supervisor/temail-*.conf.example` |

## Scheduler

Registered in `bootstrap/app.php` (all listed mail/billing/process jobs use `withoutOverlapping()` where applicable):

| Cadence | Command |
| --- | --- |
| Every minute | `processes:scheduler-heartbeat` |
| Daily/hourly (flag) | `logs:cleanup` |
| Daily (flag) | `inbound:cleanup`, `inboxes:expire`, `outbound:prune` |
| Hourly | `outbound:verify-domains` |
| Every 5 minutes | outbound stale-sending reconcile; billing renewal/grace/expire/checkout/sync |
| Every 15 minutes | outbound unmatched events / events / usage reconcile |
| Every minute | `outbound:dispatch-scheduled` |
| Daily | `billing:prune-webhook-security` |

Idempotent / fail-closed execution is expected for lifecycle and reconcile commands (established in prior prompts).

## Health

| Command | Role |
| --- | --- |
| `inbound:health` | Inbound processing metrics / breaches |
| `outbound:launch-readiness` | Transport, queues, domain auth, flags, rollout |
| `outbound:status` | Ops status |
| `processes:health` / `processes:runtime-smoke` | Worker/scheduler heartbeats, backlog |
| `attachments:scanner-health` | ClamAV (adjacent; not MTA) |
| `billing:stripe-health` / `billing:sslcommerz-health` | Billing (sanity coupling) |

**Explicitly out of scope for Prompt 651:** new SMTP probe commands, MX health checks.

## Mail server inventory

| Abstraction | Role |
| --- | --- |
| `MailProvider` enum | Label (e.g. postfix, mailgun, ses, smtp) |
| `MailServer` model | Platform inventory for inbox pool assignment / capacity metadata |
| `MailServerSelectionService` | Selection for provisioning |

`MailServer::healthy()` is timestamp-based inventory semantics, **not** proof of a running MTA. No assumption that inventory rows represent operated Postfix/Exim hosts.

## TLS

| Check | Result |
| --- | --- |
| Outbound SMTP encryption defaults | PASS — `OUTBOUND_SMTP_ENCRYPTION=tls`, `VERIFY_PEER=true` |
| Transport rejects invalid encryption config | PASS — validator |
| Production webhook / app over HTTPS | PASS — required by deployment docs / runbook |
| Certificate management | Documented in deployment checklist (edge/proxy responsibility) |

## Security

| Check | Result |
| --- | --- |
| Inbound webhook HMAC + skew | PASS |
| Owner isolation on outbound / inbox paths | PASS — authorization services + prior tests |
| No secret logging in health/readiness | PASS — safe report shaping on launch readiness |
| Secrets via environment | PASS — `.env.example` contract |
| Browser / client non-authoritative for mail accept | PASS — accept is signed webhook / queued outbound only |
| Least privilege on failure replay | PASS — platform admin only |

## Monitoring

Structured metrics/audit exist for inbound counters, outbound ops metrics, audit log actions, queue readiness, and process heartbeats. No new monitoring system introduced by this audit.

## Operations & deployment

Companions:

- [MAIL_INFRASTRUCTURE_OPERATIONS_RUNBOOK.md](MAIL_INFRASTRUCTURE_OPERATIONS_RUNBOOK.md)
- [MAIL_INFRASTRUCTURE_DEPLOYMENT_CHECKLIST.md](MAIL_INFRASTRUCTURE_DEPLOYMENT_CHECKLIST.md)

Also related: `docs/PRODUCTION_RUNBOOK.md`, `docs/OUTBOUND_WORKER_DEPLOYMENT.md`, `docs/OUTBOUND_LAUNCH_RUNBOOK.md`, `docs/INBOUND_ROUTING_CONTRACT.md`.

## Deferred register

```text
Deferred by design (architectural decisions, not missing features):
- Native SMTP receiver
- LMTP
- Direct MX delivery into this application
- Postfix
- Exim
- Dovecot
- SMTP probing commands
- MX probing commands
- Self-hosted MTA cluster / high availability (Prompt 652+)
```

## Regression (audit execution)

| Suite | Result |
| --- | --- |
| Inbound filter | PASS — 38 passed |
| Outbound filter | PASS — 313 passed, 1 skipped |
| Webhook / DNS / MailServer filter | PASS — 55 passed, 1 skipped |
| Billing invoice lifecycle (sanity) | PASS — 11 passed (isolated; wide billing filter can hit API rate limits mid-suite) |
| Full repository suite (caches cleared) | PASS — 981 passed, 12 skipped |

Expected skips: `RUN_OUTBOUND_SMTP_TESTS`, `RUN_CLAMAV_TESTS`, relational concurrency harnesses, and similar environment-gated checks.

## Static analysis / tooling

| Check | Classification |
| --- | --- |
| Pint (dirty paths) | PASS |
| PHPStan changed paths | PASS — docs-only change set (no PHP) |
| Full PHPStan | Existing baseline — 233 pre-existing errors (KNOWN; unchanged by this audit) |
| Config cache | PASS |
| Route cache | PASS |
| Scheduler list | PASS |
| `git diff --check` | PASS |

## Known limitations (accepted)

- Native SMTP/MX/MTA not part of production boundary.
- `ProcessInboundMessageJob` uses the default queue name unless remapped; named `mail-ingestion` / parsing / storage keys are reserved foundation names — ensure workers consume the queue that actually receives inbound jobs.
- `MailServer` health timestamps are inventory metadata, not live MTA probes.
- Generic provider DKIM expectations may be advisory/empty depending on provider config; SES path expects configured DKIM tokens.
- Outbound remains kill-switch closed until operators enable flags and complete launch readiness.
- Full PHPStan repository baseline may remain red with pre-existing debt outside mail paths (KNOWN from billing audit).

## Acceptance

Within the frozen app-orchestrated architecture: signed inbound webhook, external SMTP outbound, queue-backed processing, DNS authentication documentation, operations runbook, and deployment checklist are verified. Mail infrastructure is **production-audited** subject to checklist completion in the target environment.
