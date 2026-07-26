# Final outbound release audit

Audit date: 2026-07-26. Baseline: `bb99962e21831114ffd54f0d069aebf696c6ca04`. Final commit candidate: Prompt 625A completion on `main` (see git HEAD after merge of this audit).

| Area | Expected contract | Implementation location | Test coverage | UI coverage | API coverage | Operational coverage | Finding | Severity | Action |
|---|---|---|---|---|---|---|---|---|---|
| Lifecycle | Draft through delivered/failed with terminal precedence | actions, delivery jobs, provider processor | Foundation, send, schedule, delivery tests | list/detail/timeline | message/draft routes | reconciliation commands | Contract and transition guards present | Accepted limitation | No change |
| Ownership | Every object owner-scoped | controllers, access services, policies | cross-user feature tests | authenticated routes | API key scopes | audit attribution | No cross-owner production path found | Accepted limitation | No change |
| Identity/content | Server-owned identity and sanitized bodies/headers | validators, content/header guards, transports | send/reply/forward/profile tests | sanitized previews | validated DTO requests | fail-closed transport readiness | Controls present | Accepted limitation | No change |
| Attachments | Clean-only, revalidated, private | selector, scanner jobs, download controllers | attachment/quarantine tests | safe status/download | owner-scoped download | scanner health commands | Fail-closed path present | Accepted limitation | No change |
| Usage/abuse | Atomic accounting and all-path controls | usage, suppression, rate limiter | usage/abuse/suppression tests | safe usage summaries | scoped usage route | reconciliation/status | No double-charge/bypass found | Accepted limitation | No change |
| Scheduling | Versioned, claimed, at-most-once dispatch | schedule actions/command | schedule and concurrency tests | edit/send-now/cancel | scoped schedule routes | minute scheduler and lock | Indexed due path present | Accepted limitation | No change |
| Notifications | Safe, deduplicated, isolated, retained | notification service/job/controllers | notification tests | list/read/dismiss/preferences | scoped list/read/dismiss/preferences | dedicated queue and prune | Missing web dismiss, prune path, worker template | P1/P2 | Fixed and regression-tested |
| Navigation/accessibility | Complete discoverable navigation | application layout/views | web feature tests | responsive authenticated nav | n/a | n/a | Scheduled/preferences links and unread status absent | P2 | Fixed |
| Retention/privacy | Bounded, idempotent, content-free evidence | prune service/policies | retention tests | safe missing/redacted fallback | hidden owner resources | scheduled prune | Notification retention documentation exceeded implementation | P1 | Fixed |
| Deployment/docs | Every async path has worker/config contract | config, Supervisor examples, docs | config/readiness tests | n/a | n/a | scheduler/workers/readiness | Notification queue worker and env contract incomplete | P1 | Fixed |

## Release findings

No P0 finding was identified.

### P1 resolutions

1. **Notification retention** — `OutboundPruneService::pruneNotifications()` deletes expired `outbound_notifications` rows in a single bounded batch (`limit($batchSize)`, `MAX_CANDIDATE_SCAN` for eligible counts), gated by `OUTBOUND_NOTIFICATION_RETENTION_DAYS` (default 90) and `outbound_retention.cleanup_enabled`. Scheduled via `outbound:prune --confirm` when cleanup is enabled. Touches only the `OutboundNotification` model (not Laravel `notifications`).
2. **Notification queue worker** — `deploy/supervisor/temail-notification-worker.conf.example` runs `--queue=notifications` with documented tries/timeout/backoff. `.env.example` documents `SYSTEM_NOTIFICATION_*`, retention, and usage-warning variables. Queue name matches `config/queue.php` / `QUEUE_NOTIFICATIONS`.

### P2 resolutions

1. **Web dismissal** — Owner-scoped `DELETE outbound-notifications/{notification}` with CSRF, `@method('DELETE')`, and `confirm()` UI. Cross-user dismissal returns 404. Repeated dismiss is idempotent (`dismissed_at ?? now()`).
2. **Primary navigation** — Scheduled filter link, Notification Preferences link, and unread count badge in `layouts/app.blade.php` (Blade `@if` kept on its own lines so Livewire/Blade compile correctly).

### P3

Consolidated release audit documentation (this file) updated with final verification evidence.

## Accepted limitations

| Limitation | Classification |
|---|---|
| Automatic provider failover absent | accepted limitation |
| Only one live provider transport adapter | accepted limitation |
| Suppression hard-delete deferred | accepted limitation |
| SQLite concurrency proofs are limited | accepted limitation |
| Sender-profile default uniqueness is transactional rather than DB partial unique | accepted limitation |
| Signature replacement is best-effort for heavily edited bodies | accepted limitation |
| Other unused Feature helpers may remain file-local | accepted limitation |

None of the above create user isolation failure, privacy leakage, duplicate send, double usage charge, fail-open security, data corruption, or a missing production execution path.

## Verification evidence (Prompt 625A)

### Runtime / lingering-process diagnosis

- Pre-run PHP process count: **0**
- Prior agent timeouts were caused by Blade `ParseError` stack dumps after a bad `Notifications@if` compile (directive glued to preceding word), not by prune deadlocks or infinite loops
- `pruneNotifications` has no `while` loop; single bounded `pluck` + `delete`
- After successful suites: PHP process count **0** (serial and parallel workers exit)

### Focused tests

| Filter / group | Result |
|---|---|
| `OutboundNotification` | 13 passed |
| `OutboundRetention` | 21 passed |
| `OutboundSchedule` | 18 passed |
| `OutboundOperations` | 5 passed |
| `OutboundAbuse` | 5 passed |
| `Outbound` (broad) | 309 passed, 1 skipped |
| `tests/Unit` | 42 passed, 1 skipped |
| `tests/Feature` | 763 passed, 6 skipped |

### Full suites

| Suite | Result |
|---|---|
| Serial `php artisan test` | **805 passed, 9 skipped, 0 failed** |
| Parallel `php artisan test --parallel` | **805 passed, 9 skipped, 0 failed** (4 processes; workers exited) |

### Configuration verification

- `php artisan config:cache` / `config:clear`: OK
- Routes include web dismiss, notification APIs, preferences, scheduled message routes
- Scheduler includes `outbound:dispatch-scheduled` every minute; `outbound:prune --confirm` daily when `outbound_retention.cleanup_enabled=true`
- Post-`config:clear` retests: OutboundNotification / Retention / Schedule / Abuse all PASS

### Static analysis

| Scope | Result |
|---|---|
| Global `vendor/bin/phpstan analyse` | **250 errors** (pre-existing baseline) |
| Prompt 625 new/modified controller | **0 errors** |
| Prompt 625 `pruneNotifications` addition | **0 net-new errors** |
| Pre-existing `OutboundPruneService` issues (unrelated lines) | 6 baseline errors unchanged; not fixed per policy |

### Documentation consistency

Aligned with `docs/OUTBOUND_NOTIFICATIONS.md`, `docs/OUTBOUND_WORKER_DEPLOYMENT.md`, `.env.example`, and Supervisor notification worker template.

## Final release decision

**READY WITH ACCEPTED LIMITATIONS**

No P0 or P1 remains. All required tests pass (serial and parallel). No net-new static-analysis errors from Prompt 625. Production prune, notification worker, web dismiss, and navigation paths are complete. Remaining limitations are documented and non-blocking.
