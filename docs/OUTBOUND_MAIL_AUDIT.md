# Outbound Mail Production Audit (Prompt 655)

Production-readiness audit of the outbound mail lifecycle from draft through delivery, suppression, notifications, reconciliation, and retention. Architecture unchanged: no new SMTP provider, queue topology, billing redesign, or browser-authoritative send path.

**Audit date:** 2026-07-29  
**Branch:** `feature/billing-payments`  
**Baseline HEAD:** `9f02bee` (after Prompt 654)  
**Final decision:** **PASS** (with accepted limitations from Prompt 625A)

## Frozen architecture (verified unchanged)

```text
Draft
  → Submission
  → Outbound Queue
  → Transport
  → Provider
  → Verified Provider Event
  → Delivery State
```

No new SMTP providers, queue topology changes, billing redesign, provider-specific business-logic duplication, or browser-authoritative send introduced.

## Authoritative components

| Concern | Implementation |
| --- | --- |
| Draft | `OutboundDraftService`, draft API/web controllers |
| Submit / send | `CreateOutboundSendAction`, draft `submit()`, reply/forward actions |
| Queue | `DeliverOutboundMessageJob` (`outbound-delivery`, `ShouldBeUnique`) |
| Transport | `OutboundTransportInterface` → `LaravelMailOutboundTransport` / `UnavailableOutboundTransport` |
| Provider events | `OutboundWebhookController`, `OutboundProviderEventProcessor` |
| State | `OutboundMessageState` enum + precedence ranks |
| Suppression | `OutboundSuppressionService` |
| Notifications | `OutboundNotificationService` + `SendOutboundNotificationEmailJob` |
| Reconciliation | stale-sending, unmatched events, events, usage commands |
| Usage | `OutboundUsageService` (reserve → commit / release) |
| Ops | `OutboundOpsService`, `outbound:status`, launch readiness |

Related docs: `OUTBOUND_EMAIL_CONTRACT.md`, `OUTBOUND_RELEASE_AUDIT.md`, `OUTBOUND_LAUNCH_RUNBOOK.md`, `OUTBOUND_WORKER_DEPLOYMENT.md`, `OUTBOUND_USAGE_ACCOUNTING.md`, `OUTBOUND_NOTIFICATIONS.md`, `OUTBOUND_SENDER_PROFILES.md`, `OUTBOUND_RETENTION_POLICY.md`.

---

## 1. Draft lifecycle — PASS

| Check | Result |
| --- | --- |
| Create / update | `OutboundDraftService`; drafts are `OutboundMessage` rows with `state=draft` |
| Optimistic concurrency | `draft_version` + `assertVersion()` on update / submit / delete |
| Ownership | `user_id` scoping; policy / access service |
| Sender profile | Optional linkage via `OutboundSenderProfileService` |
| Attachments | `attachment_ids` validated at submit via `OutboundAttachmentSelector` |
| Sanitization | `OutboundContentValidator` (HTML via `InboundHtmlSanitizer`, header stripping) |
| Deletion | Soft via `draft_deleted_at` |
| Retention | `OutboundPruneService` + retention holds |
| Submit immutability | Atomic `draft` → `queued`; further edits blocked |

**Verified flow:** Draft → Submitted (`queued`) → Immutable outbound message.

---

## 2. Submission — PASS (fail-closed)

Gates applied before queue (and re-checked in worker where required):

- Authorization / ownership / active inbox / domain outbound + auth
- Entitlement (`send_email` / `reply_email` / `forward_email`)
- Rollout + emergency stop (`OutboundLaunchControlService`)
- Suppression (`assertRecipientsAllowed`)
- Abuse / rate limits (`OutboundRateLimiter`)
- Usage reservation (`OutboundUsageService::reserve`)
- Idempotency `(user_id, idempotency_key)` + fingerprint conflict
- Recipient / content / attachment / reply-forward rules

Defaults fail closed (`OUTBOUND_ENABLED=false`, transport `unavailable`, `OUTBOUND_EMERGENCY_STOP=true`).

---

## 3. Queue — PASS

| Check | Result |
| --- | --- |
| Assignment | `outbound-delivery` workload |
| Unique job | `ShouldBeUnique` / `uniqueId` = message id / `uniqueFor=3600` |
| After-commit | Used on scheduled dispatch + notifications; direct submit dispatches post-transaction (safe) |
| Retry / backoff | `send_max_attempts` + `send_backoff_seconds` |
| Timeout | Validated by `OutboundWorkerConfigValidator` (SMTP < job < retry_after) |
| Duplicate prevention | Unique job + atomic `queued` → `sending` claim |
| Failure handling | Temporary → re-queue; permanent / exhausted → `failed` |

---

## 4. Transport — PASS

- Provider-neutral `OutboundTransportInterface`
- Live adapter: Laravel mailer SMTP (`LaravelMailOutboundTransport`)
- Fail-closed default: `UnavailableOutboundTransport`
- TLS / credential isolation via env + `OutboundTransportConfigValidator`
- Header injection blocked by `OutboundHeaderGuard`
- No provider-specific business logic in transport

---

## 5. Provider interaction — PASS

- Send request through transport; provider id / correlation recorded on attempts
- Idempotent job + unique delivery identity
- Transient vs permanent mapped via `OutboundTransportResult` / failure mapper
- Retry-safe: claim + attempt recorder; usage commit only on acceptance path

---

## 6. Provider events — PASS

Verified webhook ingress only (SNS / HMAC). Event types normalized; replay-safe idempotent ingest; legal transitions via precedence (`cancelled` > `delivered` > `failed` > `sent` …). Bounce/complaint drive suppression; `delivered` never set from SMTP acceptance alone.

---

## 7. State machine — PASS (actual)

```text
draft → scheduled | queued
scheduled → draft | queued | cancelled | scheduled (reschedule)
queued → sending | cancelled
sending → sent | failed | queued (retry)
sent → delivered | failed
failed → queued (manual/system retry)
delivered / cancelled → (terminal)
```

There is no separate `suppressed` message state; suppression blocks recipients at submit and delivery-job recheck.

---

## 8. Suppression — PASS

- Permanent bounce + complaint auto-suppress; temporary failure does not
- Manual admin suppress / unsuppress
- Active + non-expired hash lookup; owner audit on block
- Delivery job rechecks after queueing
- Hard-delete of suppression rows deferred (accepted limitation)

---

## 9. Notifications — PASS

Lifecycle: queued, sent, delivered/failed/cancelled, schedule deferred/failed, usage warning/exhausted. Deduplicated by idempotency key; owner-scoped API/web; email on `notifications` queue. Web dismiss CSRF regression fixed in tests (forms already sent `@csrf`).

---

## 10. Reconciliation — PASS

| Command | Cadence |
| --- | --- |
| `outbound:reconcile-stale-sending` | every 5 min |
| `outbound:reconcile-unmatched-events` | every 15 min |
| `outbound:reconcile-events` | every 15 min |
| `outbound:reconcile-usage` | every 15 min (dry-run default) |

All use `withoutOverlapping`. Updates are idempotent / fail-closed for ambiguous transport attempts.

---

## 11. Sender profiles — PASS

Ownership, default profile policy, Reply-To, signature + HTML sanitization, from-address always owned inbox. Default uniqueness transactional (accepted limitation).

---

## 12. Attachments — PASS

Forward-only clean attachments; owner-scoped download; size/count limits; missing file fail-closed; no cross-owner leakage. Malware certification deferred to Prompt 656.

---

## 13. Commercial integration — PASS

Entitlement + reserve/commit/release; quota exhaustion fail-closed; Free / downgrade / grace via entitlement service; cancellation releases pre-transport reservations. Provider retries do not double-commit usage.

---

## 14. API — PASS

Draft CRUD + submit/schedule; message list/detail/timeline/cancel/retry/schedule; notifications + preferences; sender profiles; reply/forward; usage. Owner isolation + API scopes + entitlements.

---

## 15. Security — PASS

Authorization, header injection guard, HTML sanitization, attachment authorization, audit logging, env-only secrets, verified provider webhooks, emergency stop.

---

## 16–18. Operations / monitoring / scheduler — PASS

See `OUTBOUND_MAIL_RUNBOOK.md` and `OUTBOUND_MAIL_DEPLOYMENT_CHECKLIST.md`. Monitoring via `outbound:status`, ops admin pages, cache metrics — no new monitoring platform.

---

## Regression evidence (Prompt 655)

| Group | Result |
| --- | --- |
| Draft | 4 passed |
| Send | 8 passed |
| Provider (+ SES) | 26 passed |
| Commercial | 2 passed |
| Notifications | 13 passed |
| Suppression | 4 passed |
| Reconciliation (events + stale) | 17 passed |
| Full `--filter=Outbound` | **313 passed, 1 skipped** |

Environment note: suite must force `DB_CONNECTION=sqlite` / `DB_DATABASE=:memory:` when a local `.env` points at MySQL. Config cache left active during an earlier full run polluted results; cleared afterward.

### Tooling

| Check | Result |
| --- | --- |
| Pint (repo) | Pre-existing failures outside outbound audit scope |
| PHPStan changed paths | Pest helpers baseline noise on test file; no outbound production code changes |
| Full PHPStan | 231 baseline errors (pre-existing) |
| `config:cache` / `route:cache` | OK (cleared after verification) |
| Scheduler | Outbound jobs listed with `withoutOverlapping` |
| `git diff --check` | Clean |

---

## Accepted limitations (unchanged from 625A)

- No automatic provider failover
- Single live SMTP transport adapter
- Suppression hard-delete deferred
- SQLite concurrency proofs limited
- Sender-profile default uniqueness transactional
- Signature replacement best-effort on heavily edited bodies

None create isolation failure, privacy leakage, duplicate send, double usage charge, fail-open security, or missing production execution path.

## Final decision

**Outbound mail production ready: YES**

No P0 production blockers found. Architecture frozen. Focused and broad outbound regressions pass. Ops docs added under the Prompt 655 naming contract.
