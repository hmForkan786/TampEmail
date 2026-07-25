# Outbound Launch Runbook (Prompt 615)

Staged production rollout, canary controls, launch readiness, launch
metrics, pause recommendations, and emergency shutdown for outbound email.
Builds on `docs/OUTBOUND_EMAIL_CONTRACT.md`, `docs/OUTBOUND_WORKER_DEPLOYMENT.md`,
and the domain-authentication contract in
`app/Services/Outbound/OutboundDomainAuthenticationService.php`. Read those
first; this document only covers launch-control-specific behavior.

## Launch-control model

```env
OUTBOUND_ENABLED=false
OUTBOUND_ROLLOUT_MODE=disabled     # disabled|canary|percentage|enabled
OUTBOUND_ROLLOUT_PERCENT=0
OUTBOUND_EMERGENCY_STOP=true
```

Everything defaults **closed**: `OUTBOUND_ENABLED=false` (existing kill
switch), `OUTBOUND_ROLLOUT_MODE=disabled`, `OUTBOUND_ROLLOUT_PERCENT=0`,
`OUTBOUND_EMERGENCY_STOP=true`. No outbound traffic ships until an operator
explicitly opts in, layer by layer.

Evaluation order in `App\Services\Outbound\OutboundLaunchControlService::assertRolloutEligible()`
(called from `OutboundAuthorizationService::assertCanSend()`, so it applies
to every send/reply/forward/retry path and to the delivery worker):

1. **Emergency stop** — checked first, overrides every other enablement
   (including canaries and a 100% rollout).
2. Existing checks unchanged: user active, inbox ownership/active, domain
   active + `outbound_enabled` + verified (SPF/DKIM per
   `OutboundDomainAuthenticationService`), `outbound.enabled`,
   per-operation flag (`send_enabled`/`reply_enabled`/`forward_enabled`),
   plan entitlement.
3. **Rollout mode**:
   - `disabled` — blocked for everyone (`outbound_rollout_disabled`).
   - `canary` — only admin-flagged canary users/inboxes/domains/API keys
     (`outbound_rollout_not_canary` otherwise).
   - `percentage` — deterministic hash of the user id vs.
     `OUTBOUND_ROLLOUT_PERCENT` (`outbound_rollout_percentage_excluded`
     otherwise). **Never per-request randomness** — the same user always
     lands on the same side of a given percent boundary, so a user does not
     flip in and out of eligibility between requests.
   - `enabled` — no additional restriction.
   - Any other value — fails closed exactly like `disabled`
     (`outbound_rollout_mode_invalid`).

Canary membership and percentage rollout are **strictly additive**: they
never bypass domain verification, suppression, quota/abuse checks, or
worker readiness, which remain independent checks evaluated elsewhere in
the same call chain.

### Live overrides (admin page)

`config/outbound.php` (env-driven) is the deployment default. An
authorized platform admin can additionally apply an audited, cache-backed
override from the **Outbound Launch Control** admin page (or by calling
`OutboundLaunchControlService::setRollout()` / `setEmergencyStop()`
directly) to flip state instantly without a redeploy. Overrides take
priority over the env default until cleared
(`OutboundLaunchControlService::clearOverrides()`). Every change is
audited (`outbound.launch_rollout_changed`,
`outbound.launch_emergency_stop_changed`, `outbound.launch_overrides_cleared`).

## Canary controls

`OutboundLaunchCanary` (table `outbound_launch_canaries`) stores the
smallest-scope subject that fits each identity: `user`, `inbox`, `domain`,
or `api_key`. Add/remove is admin-only and fully audited
(`outbound.canary_added`, `outbound.canary_removed`) via
`App\Services\Outbound\OutboundCanaryService`. A subject must exist before
it can be added (fails closed with `canary_subject_not_found` otherwise).

Canary matching (`OutboundCanaryService::matches()`) checks the acting
user id, the sending inbox id, the inbox's domain id, and (at message
creation time only) the API key id used for the request. It is consulted
in two independent ways:

- **Rollout gating** — only enforced when `OUTBOUND_ROLLOUT_MODE=canary`.
- **`is_canary` tracking** — `outbound_messages.is_canary` is set at
  creation time for *any* matching identity, independent of the current
  rollout mode, so canary volume/outcomes stay measurable even while the
  rollout is in `percentage` or `enabled` mode.

## Readiness gate

```bash
php artisan outbound:launch-readiness --json
```

Evaluates (via `App\Services\Outbound\OutboundLaunchReadinessService`):
transport (`OutboundTransportConfigValidator`), provider parser + webhook
secret/topic-ARN presence, queue topology + worker/scheduler heartbeats
(`OutboundQueueReadinessService`), domain verification counts
(`OutboundDomainAuthentication`), feature flags, plan-feature catalog
entries (`send_email`/`reply_email`/`forward_email`), suppression/abuse
subsystem availability, required table migrations, and the rollout/
emergency-stop state plus `OutboundLaunchConfigValidator`.

Returns one of:

| Status | Meaning |
|---|---|
| `disabled` | Intentionally off: `outbound.enabled=false`, emergency stop engaged, or rollout mode `disabled`. Expected pre-launch state. |
| `blocked` | Something required is broken or missing (invalid config, unsupported mode, no verified domain, missing webhook secret, pending migrations). Do not proceed to a live rollout mode. |
| `degraded` | Operating with non-blocking issues (worker/scheduler heartbeat stale, pending domain verification, config warnings). |
| `ready` | All checks pass. Safe to consider widening the rollout. |

**Never sends a test email.** That is the explicit, separate job of
`outbound:canary-send`.

## Optional canary send

```bash
RUN_OUTBOUND_SMTP_TESTS=1 php artisan outbound:canary-send \
  --actor=<platform-admin-user-id> \
  --inbox=<inbox-id> \
  --recipient=<approved-recipient-email> \
  --json
```

- **Never runs automatically** — gated by `RUN_OUTBOUND_SMTP_TESTS=1`
  (`outbound.launch.canary_send.enabled`); absent that flag the command
  exits with `canary_send_disabled` and sends nothing.
- **Admin-only** — `--actor` must resolve to a platform-admin user, or the
  command rejects with `actor_invalid`.
- **Approved recipient only** — `--recipient` must be in
  `OUTBOUND_CANARY_SEND_ALLOWED_RECIPIENTS` (comma-separated allow-list;
  empty list means nothing is approved, fail closed).
- **Idempotent** — the idempotency key is bucketed per hour/inbox/recipient,
  so repeat invocations inside the same hour return the same message
  instead of sending twice.
- **Rate limited** — `OUTBOUND_CANARY_SEND_RATE_LIMIT_PER_HOUR` (default 3).
- **No attachment by default.**
- **Clear subject** — prefixed with `OUTBOUND_CANARY_SEND_SUBJECT_PREFIX`
  (default `[Outbound Canary Test]`).
- Goes through the same `CreateOutboundSendAction` → authorization →
  suppression → rate-limit → `DeliverOutboundMessageJob` path as a real
  send (reused, not re-implemented), so it exercises the exact production
  pipeline including the rollout gate above.
- Reports `result: accepted` (transport-level acceptance, i.e. `state=sent`)
  — **never `delivered`**. Final delivery only comes from a verified
  provider event, never from this command.

**Do not run this command without real, approved SMTP credentials and an
approved recipient.** It sends a real email through the configured
transport.

## Emergency stop

Set `OUTBOUND_EMERGENCY_STOP=true` (default) or flip it live from the
admin page / `OutboundLaunchControlService::setEmergencyStop()`.

- Blocks new sends/replies/forwards
  (`CreateOutboundSendAction`/`CreateOutboundReplyAction`/`CreateOutboundForwardAction`
  → `assertCanSend()` throws `outbound_emergency_stop`, HTTP 503, before any
  row is created).
- Blocks manual retry (`RetryOutboundMessageAction` → same check; the
  message stays in its prior `failed` state, it is not touched).
- Blocks queued messages from starting transport: `DeliverOutboundMessageJob`
  checks emergency stop **before** claiming the message (before the
  `queued → sending` transition). If stopped, it writes an
  `outbound.emergency_stop_blocked` audit entry and releases the job back
  onto the queue with a delay
  (`OUTBOUND_EMERGENCY_STOP_RETRY_DELAY_SECONDS`, default 300s) — the
  message row is **never touched**, so it is never marked `failed` and
  never deleted solely because of the stop.
- **Allows** provider webhook ingestion and ops visibility
  (`outbound:status`, `outbound:launch-readiness`, the admin pages) to keep
  working normally while stopped, so incident responders retain full
  observability.
- Fully auditable: `outbound.launch_emergency_stop_changed` (config change)
  and `outbound.emergency_stop_blocked` (each blocked delivery attempt).

To resume: clear the stop, then either wait for the delayed job release to
retry naturally, or use the existing authorized retry/reconciliation tools
(`outbound:reconcile-stale-sending`, `outbound:reconcile-events`,
`POST /api/v1/outbound-messages/{id}/retry`) — never a blanket
`queue:retry all` (see `docs/OUTBOUND_WORKER_DEPLOYMENT.md`).

## Launch metrics

```bash
php artisan outbound:launch-readiness --json   # readiness + rollout state
php artisan outbound:status --json             # existing ops volume/retry/provider/suppression/abuse metrics
```

`App\Services\Outbound\OutboundLaunchMetricsService::metrics()` (surfaced
on the admin page) adds, over a configurable window (default 24h): canary
sends/accepted/delivered/failed, accepted/delivered counts, temporary/
permanent failure counts, bounce/complaint rate percentages, active/added
suppressions, abuse-blocked sends, oldest queued age, retries exhausted,
unmatched and terminal-unmatched provider events, ambiguous acceptance
(`reconciliation_flagged_at` count), invalid-signature attempts, provider
auth-failure count, per-queue worker health, and verified-domain count. It
reuses `OutboundOpsService` and `OutboundQueueReadinessService` rather than
re-deriving their queries.

## Automatic pause recommendation

`App\Services\Outbound\OutboundLaunchRecommendationService::recommend()`
returns `continue`, `hold`, or `rollback` plus the specific reasons,
derived from the metrics above against configurable thresholds
(`config('outbound.launch.thresholds')`, all overridable via
`OUTBOUND_LAUNCH_*` env vars):

| Signal | Hold threshold | Rollback threshold |
|---|---|---|
| Bounce rate | `OUTBOUND_LAUNCH_HOLD_BOUNCE_RATE_PERCENT` (5%) | `OUTBOUND_LAUNCH_ROLLBACK_BOUNCE_RATE_PERCENT` (10%) |
| Complaint rate | `OUTBOUND_LAUNCH_HOLD_COMPLAINT_RATE_PERCENT` (1%) | `OUTBOUND_LAUNCH_ROLLBACK_COMPLAINT_RATE_PERCENT` (3%) |
| Provider auth failures | — | `OUTBOUND_LAUNCH_PROVIDER_AUTH_FAILURES` (3) |
| Invalid-signature attempts | — | `OUTBOUND_LAUNCH_INVALID_SIGNATURE_ATTEMPTS` (5) |
| Oldest queue age | `OUTBOUND_LAUNCH_OLDEST_QUEUE_AGE_SECONDS` (1800s) | — |
| Unmatched events | `OUTBOUND_LAUNCH_UNMATCHED_EVENTS` (10) | — |
| Ambiguous acceptance | `OUTBOUND_LAUNCH_AMBIGUOUS_ACCEPTANCE` (5) | — |
| Missing worker/scheduler heartbeat | any missing | — |

**Purely advisory.** Nothing in this codebase auto-disables outbound based
on this recommendation — an operator must act on it explicitly (flip
`OUTBOUND_EMERGENCY_STOP` or the rollout mode/percent), matching the
existing policy that abuse/suppression/queue signals only ever surface as
`issues`/reports, never as silent auto-disablement.

## Admin launch page

**Outbound Launch Control** (Filament, `Operations` navigation group,
platform-admin only, `App\Filament\Admin\Pages\OutboundLaunchControlPage`):

- Rollout mode/percent editor and emergency-stop toggle (live overrides,
  see above).
- Canary add/remove with an active-canary table.
- Readiness, domain/worker/webhook/provider readiness detail, launch
  metrics, and the pause recommendation.
- Every state-changing action re-checks platform-admin authorization
  inside the handler and requires an explicit confirmation
  (`wire:confirm`) before it runs — matching the existing
  `OutboundRecipientSuppressions` / `AttachmentScannerOps` admin-page
  conventions in this codebase.
- Loading the page **never** sends a test email or mutates DNS/config
  files.

## Config validation

`App\Services\Outbound\OutboundLaunchConfigValidator::validate()` returns
`errors` (block the change) and `warnings` (informational). It never
rewrites `.env` or any file — it is a pure read-only report consulted by
the readiness gate, `setRollout()` (blocks an invalid live change), and the
admin page.

Errors (fail closed):

- `rollout_mode_unsupported` — mode is not one of `disabled|canary|percentage|enabled`.
- `rollout_percent_out_of_range` — percent outside 0-100 (checked against
  the raw configured/overridden value, before any runtime clamping).
- `canary_mode_without_canaries` — mode is `canary` with zero active
  canaries.
- `enabled_without_valid_transport` / `enabled_without_ready_queues` /
  `enabled_without_verified_domain` / `enabled_without_webhook_secret` —
  any "live traffic" mode (`canary`/`percentage`/`enabled`) without a
  passing transport check, healthy queue topology, at least one verified
  outbound domain, or the required webhook secret/topic ARN for the
  configured provider.

Warnings (informational only):

- `rollout_configured_while_globally_disabled` — a live rollout mode is
  configured but `OUTBOUND_ENABLED=false` and emergency stop is off (the
  rollout config will simply have no effect until `OUTBOUND_ENABLED=true`).
- `emergency_stop_active_with_live_rollout_mode` — a live rollout mode is
  configured but emergency stop is currently engaged (informational: the
  stop is doing the blocking, not the mode).

## Final launch checklist

1. `php artisan migrate` — all outbound tables present
   (`outbound:launch-readiness` fails `blocked`/`migrations_pending`
   otherwise).
2. Configure and verify transport credentials
   (`OUTBOUND_SMTP_*`/mailer config); `outbound:launch-readiness` reports
   `transport.valid=true`.
3. Verify at least one outbound domain (`outbound:verify-domains`, or the
   **Outbound Domain Authentication** admin page) until
   `domain.verified_count >= 1`.
4. Configure the provider webhook secret (`OUTBOUND_GENERIC_DELIVERY_WEBHOOK_SECRET`)
   or SNS topic ARN (`OUTBOUND_SES_SNS_TOPIC_ARN`) for the configured
   `OUTBOUND_PROVIDER`.
5. Confirm workers/scheduler are running:
   `php artisan outbound:status --json` → `queue.delivery`/`queue.events`
   fresh workers > 0, `queue.maintenance.scheduler_fresh=true`.
6. Add the first canaries (admin page or `OutboundCanaryService::add()`),
   set `OUTBOUND_ROLLOUT_MODE=canary` (or apply the equivalent live
   override), and confirm `outbound:launch-readiness --json` returns
   `ready`.
7. Set `OUTBOUND_ENABLED=true` (and the relevant `OUTBOUND_*_ENABLED`
   operation flags) and clear `OUTBOUND_EMERGENCY_STOP` (set to `false`, or
   lift it live).
8. Optionally run `outbound:canary-send` (with real credentials, an
   approved recipient, and `RUN_OUTBOUND_SMTP_TESTS=1`) to prove the
   end-to-end pipeline before widening the rollout.
9. Monitor `outbound:launch-readiness --json` and the admin page's
   pause recommendation; widen `OUTBOUND_ROLLOUT_PERCENT` or switch to
   `enabled` only while the recommendation is `continue`.
10. If bounce/complaint rates spike, provider auth starts failing, or the
    recommendation reads `rollback` — engage `OUTBOUND_EMERGENCY_STOP`
    immediately (admin page or env), investigate, and only resume once the
    root cause is fixed and readiness is `ready` again.

## Verification

```bash
vendor/bin/pint --dirty
php artisan test --filter=OutboundLaunch
php artisan test --filter=Outbound
php artisan test --filter=ProviderEvent
php artisan test --filter=DomainVerification
php artisan test --filter=ProcessHealth
php artisan test --filter=Operations
php artisan test
php artisan outbound:launch-readiness --json
php artisan outbound:status --json
php artisan schedule:list
php artisan route:list
php artisan config:cache
php artisan config:clear
```

Do not run `outbound:canary-send` without real, approved credentials and an
approved recipient — it sends a real email.
