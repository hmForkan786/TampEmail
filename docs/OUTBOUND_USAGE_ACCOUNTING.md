# Outbound Usage Accounting (Prompt 618)

Status: implemented. Integrates outbound send/reply/forward activity with subscription plan entitlements and `subscription_usage` billing counters. **No payment collection, invoicing, or metered billing export is implemented here** — this is entitlement/quota accounting only.

Fully independent from `OutboundRateLimiter` (abuse/throttling, `docs/OUTBOUND_EMAIL_CONTRACT.md` § Abuse controls). This document never sets or reads abuse thresholds, and the user-visible usage endpoint never exposes them.

## Feature keys

| Key | Type | Meaning |
|---|---|---|
| `send_email` | boolean | Existing. Gates new outbound send. |
| `reply_email` | boolean | Existing. Gates reply. |
| `forward_email` | boolean | Existing. Gates forward. |
| `outbound_retention_days` | `{days: N\|null}` | Existing (Prompt 616/617). Content retention window. Unchanged by this prompt. |
| `outbound_messages_per_period` | `{limit: N\|null, reset_period: daily\|weekly\|monthly\|yearly}` | New. Metered message allowance. |
| `outbound_recipients_per_period` | `{limit: N\|null, reset_period: ...}` | New. Metered recipient (to+cc+bcc, no dedupe across messages) allowance. |
| `outbound_attachment_bytes_per_period` | `{limit: N\|null, reset_period: ...}` | New. Metered forward-attachment byte allowance. |

**Backward-compatibility policy (critical):** a plan that does not have one of the three metered features **attached at all** (no `feature_plan` pivot row) is treated as **UNLIMITED** for that dimension — never a silent fallback to the `config/outbound_usage.php` `free_defaults`. This is why every pre-existing `OutboundSendTest` / `OutboundReplyTest` / `OutboundForwardTest` test (which only attaches the boolean `send_email`/`reply_email`/`forward_email` features to their test plan) continues to pass unmodified: none of the three metered dimensions are ever checked for them.

`limit: null` in a feature's resolved value (pivot `feature_value` or catalog `default_value`) is *also* unlimited, even when the feature is attached. Only a non-null numeric `limit` on an attached feature is enforced.

`config/outbound_usage.php` `free_defaults` and the `FeatureSeeder` catalog rows exist for optional, deliberate plan provisioning only. `FeatureSeeder` (called from `DatabaseSeeder`) only `updateOrCreate`s the feature **catalog** rows (`features` table) — it never attaches a feature to any plan (`feature_plan`), so seeding it changes no existing plan's behavior.

`config('outbound_usage.metering_enabled')` (default `true`) is a master kill switch: when `false`, `OutboundUsageService::reserve/commit/release/...` are all no-ops and no `outbound_usage_reservations` rows are created — outbound send/reply/forward behaves exactly as it did before this prompt.

## Usage dimensions

Tracked via `subscription_usage` rows (one row per `(subscription_id, feature_id)` per period) for the three metered dimensions above, plus reservation-scoped metadata counters for two more:

| Dimension | Where tracked | Incremented |
|---|---|---|
| `outbound_messages_per_period` | `subscription_usage.used_value` | +1 at **commit** (provider acceptance) |
| `outbound_recipients_per_period` | `subscription_usage.used_value` | +recipient count at **commit** |
| `outbound_attachment_bytes_per_period` | `subscription_usage.used_value` | +bytes at **commit** (forward only) |
| `outbound_retries` | `outbound_usage_reservations.metadata.retries` | +1 when `DeliverOutboundMessageJob` claims an attempt with `attempt_count > 1` |
| `outbound_permanent_failures` | `outbound_usage_reservations.metadata.permanent_failures` | +1 on a terminal failure **after** a transport attempt was made |

`outbound_usage_reservations.metadata.attempts` also counts every claimed attempt (including the first), for observability.

A dimension with no active limit (unattached feature, or `limit: null`) never gets a `subscription_usage` row at all — there is nothing to charge.

## Reservation lifecycle

Table `outbound_usage_reservations`, one row per outbound message (`outbound_message_id` unique, cascade-deleted with the message):

```text
id, outbound_message_id (unique FK), user_id (FK), subscription_id (nullable FK),
operation, idempotency_key,
state: reserved | committed | released | expired,
message_units (1), recipient_units, attachment_bytes,
reserved_at, committed_at, released_at, expires_at,
release_reason, metadata (json: usage_ids, attempts, retries, permanent_failures)
```

State machine:

```text
reserved -> committed   (provider accepted / message sent; used_value += units)
reserved -> released    (pre-transport-attempt terminal outcome; used_value untouched)
reserved -> expired     (reconcile-only safety net for abandoned rows past TTL that
                          the release policy already covers but were never released)
```

`committed`, `released`, and `expired` are all terminal — none of `commit()`/`release()` ever mutate a reservation that has already left the `reserved` state, which is what makes duplicate provider events, duplicate queue job execution, and repeated cancel/retry calls idempotent.

### Chosen accounting model

Two designs were possible: increment `used_value` at reserve time (and decrement on release), or increment at commit time. **This implementation increments at commit time** (provider acceptance / message sent), because:

1. It matches the plain English meaning of "used" — a message that never left the queue, or that failed before any transport attempt, never actually consumed a send.
2. Reconciliation is simpler: `used_value` is a append-only, monotonically-increasing ledger per period; nothing needs to be un-done by comparing against a moving reserve/decrement history.
3. It naturally implements "permanent failure after an attempt does not release quota" (see below): the reservation just stays `reserved` forever for that (rare) case rather than requiring a separate "spent but not committed" state.

To prevent this from allowing more sends than the limit while messages are in flight, the **allowance check always adds outstanding `reserved` reservations to `used_value`**:

```text
allowed  ⇔  used_value + Σ(reserved units, same subscription, reserved_at ≥ current period_start) + new_units ≤ limit_value
```

This sum is computed under `lockForUpdate()` on the relevant `subscription_usage` row inside the same DB transaction that inserts the reservation, so two concurrent requests cannot both reserve past the limit (subject to the SQLite limitation below).

### Business release policy

| Outcome | Release? | Why |
|---|---|---|
| DB transaction (that created the message + reservation) fails/rolls back | Released (implicitly — reservation row itself is rolled back) | Reservation never committed to the database at all |
| Queued message cancelled (`CancelOutboundMessageAction`) | **Released** (`cancelled_before_transport`) | A queued message can never have `transport_attempted_at` set |
| `DeliverOutboundMessageJob` fails **before** the transport call (user inactive, authorization/suppression/attachment-revalidation failure) | **Released** (`pre_transport_failure`) | No transport attempt was ever made — identical in spirit to a cancel |
| `DeliverOutboundMessageJob` transport result is `temporary_failure` and attempts remain (re-queued) | Not released, not committed — reservation stays `reserved` | The message is still in flight; not a terminal outcome |
| Retry-exhausted `temporary_failure` (terminal) | **Not released** | Quota is spent by the attempt itself, regardless of provider outcome |
| `permanent_failure` / `rejected` / `configuration_failure` **after** the transport call was made | **Not released** | Same reasoning — "provider permanent reject after attempt", "invalid recipient that passed validation", "provider outage" all consume quota |
| Message accepted (`sent`) | N/A — **committed**, not released | Terminal success |
| Manual retry (`RetryOutboundMessageAction`) | N/A — does not reserve a new unit | Same message id, same reservation row |
| Idempotent replay of the same `idempotency_key` | N/A — no second reserve call | `reserve()` is a no-op once a reservation exists for that `outbound_message_id`; the create actions also return the existing message before ever calling `reserve()` again |
| Idempotency-key conflict (different payload) | N/A — no reserve at all | `OutboundSendException('idempotency_conflict', ...)` is thrown before persistence |
| Duplicate `DeliverOutboundMessageJob` execution / duplicate provider event | N/A — no double charge | The atomic `queued -> sending` / `sending -> sent` claims plus `commit()`'s terminal-state check make a second execution a no-op |
| `DeleteOutboundMessageAction` (user hides a message) | **Never releases usage** | Hiding is a visibility change, not a cancellation; a still-queued message is cancelled *first* (which releases via the cancel path above), but an already sent/failed message's usage accounting is untouched |

### Retry accounting

- A **manual retry** (`RetryOutboundMessageAction`, `failed -> queued`) never creates a new `outbound_usage_reservations` row and never re-checks the message-count allowance — it is the same message id.
- `outbound_retries` increments exactly once per **claimed** delivery attempt (i.e. once per `DeliverOutboundMessageJob::handle()` execution that successfully claims the message) where `attempt_count > 1`. A manual retry *request* that never gets claimed (e.g. because the message is no longer `failed` by the time the job runs) does not increment anything.

### Reset periods, renewal, and plan changes

- Each `subscription_usage` row is scoped to a single calendar period (`period_start`/`period_end`), computed with `Carbon::startOf{Day,Week,Month,Year}()` in the application timezone, anchored on `now()` the moment the row is first created for that feature.
- When the current period's row has `period_end < now()`, `OutboundUsageService` creates a **new** row for the new period (old rows are never deleted or mutated) — this is what "reset" means here. There is no explicit "reset job"; the reset happens lazily on first use of a new period.
- If a plan's entitled `limit` changes between periods (or mid-period), the next `reserve()`/`assertWithinAllowance()` call updates `limit_value` on the current usage row to match — never silently widening a check that is already in flight (the lock is acquired first).
- If a user's subscription changes plans mid-period, the *new* plan's entitlement is used for the very next reservation; historical `subscription_usage` rows already tied to the old subscription's period are untouched (a `subscription_usage.subscription_id` FK is `restrict`-free — rows attach to whichever subscription existed at creation time).
- No subscription (or an `expired`/`cancelled` subscription, which `EntitlementService::currentSubscription()` never selects) → `EntitlementService::getFeature()` returns `null` for **every** feature including `send_email`, so `OutboundAuthorizationService` already denies with `entitlement_denied` before `OutboundUsageService::reserve()` is ever reached. There is no "free plan" usage path distinct from the "no subscription" path in this implementation — matches existing `send_email`/`reply_email`/`forward_email` behavior.

## Hooks

1. **`CreateOutboundSendAction` / `CreateOutboundReplyAction` / `CreateOutboundForwardAction`**: `OutboundUsageService::reserve()` is called **inside** the same `DB::transaction()` that inserts the `outbound_messages` row, immediately after the insert. A quota exception (`OutboundSendException` with code `outbound_quota_{messages|recipients|attachment_bytes}_exceeded`, HTTP 429) rolls the whole transaction back — the message row is never persisted. A `UniqueConstraintViolationException`-shaped race (two requests reserving the same `outbound_message_id` — only possible via a bug, since the message row itself is also unique-keyed on `(user_id, idempotency_key)`) is handled by reloading and returning the existing reservation rather than throwing.
2. **`CancelOutboundMessageAction`**: releases unconditionally after a successful `queued -> cancelled` transition (see table above).
3. **`DeliverOutboundMessageJob`**:
   - On successful claim (`queued -> sending`): `recordAttemptStarted()` (attempts/retries metadata).
   - On transport acceptance (`sending -> sent`): `commit()`.
   - On any terminal `markFailed()` **before** the `transport_attempted_at` update: `release('pre_transport_failure')`.
   - On any terminal `markFailed()` **after** the `transport_attempted_at` update (i.e. `transport->send()` was actually invoked): `recordPermanentFailure()` — no release.
   - The re-queue path for a retryable `temporary_failure` with attempts remaining touches neither `commit()` nor `release()`.
4. **`DeleteOutboundMessageAction`**: no usage call at all (hiding ≠ cancelling; see table above).

## User-visible usage endpoint

`GET /api/v1/outbound-usage` (scope `outbound_messages:read`):

```json
{
  "data": {
    "messages_used": 12,
    "messages_remaining": 188,
    "messages_unlimited": false,
    "recipients_used": 40,
    "recipients_remaining": 460,
    "recipients_unlimited": false,
    "attachment_bytes_used": 0,
    "attachment_bytes_remaining": null,
    "attachment_bytes_unlimited": true,
    "reset_at": "2026-08-01T00:00:00+00:00",
    "entitlements": {
      "send_email": true,
      "reply_email": true,
      "forward_email": false
    }
  }
}
```

No abuse thresholds, block states, or suppression counts are ever included here — see `OutboundOpsService`/Filament **Operations → Outbound Email** for those (admin-only).

## Admin visibility

`OutboundUsageService::adminSummaryForUser(User $actor, User $target)` returns the same safe summary shape for an arbitrary user, gated by `$actor->isPlatformAdmin()` (throws `Illuminate\Auth\Access\AuthorizationException` otherwise).

`OutboundUsageService::correctUsage(User $actor, User $target, string $dimensionKey, int $newUsedValue, string $reasonCode)` performs a manual, reason-coded correction to a metered dimension's `used_value` for the target's current period. Requires `isPlatformAdmin()`; writes an `outbound.usage_corrected` audit entry with the before/after value, target user id, feature key, and reason code. No payment/credit is applied — this is a counter correction only (e.g. to undo a reconciliation mistake), never a billing action.

## Reconciliation

```bash
php artisan outbound:reconcile-usage --dry-run
php artisan outbound:reconcile-usage --confirm
php artisan outbound:reconcile-usage --confirm --batch=100
```

Dry-run by default (same convention as `outbound:prune`); `--confirm` is required to mutate anything. Bounded per `--batch` (default `config('outbound_usage.reconcile.batch_size')`, 200).

Registered in the scheduler (`bootstrap/app.php`) every 15 minutes **without** `--confirm`, so the scheduled run only ever reports drift for operator review — it never auto-repairs. An operator (or a follow-up automation change, not implemented here) must run `--confirm` explicitly to apply the deterministic repairs listed below.

Checks performed, **repaired only when deterministic**:

| Check | Repair |
|---|---|
| `sent` message whose reservation is still `reserved` (missed `commit()`, e.g. a crash between the message-state update and the commit call) | Repaired: call `commit()` |
| `reserved` rows past `reservation_ttl_seconds` whose message is `cancelled`, or `failed` with `transport_attempted_at` still `null` | Repaired: released (`ttl_expired_safety_net`) — this is a safety net for a missed `release()` call, not a new policy |
| `reserved` rows past TTL whose message state does **not** clearly justify release (e.g. still `queued`/`sending`, or `failed` **with** a transport attempt already made) | **Ambiguous** — reported only, never auto-repaired |
| Multiple reservations sharing `(user_id, idempotency_key)` but pointing at different `outbound_message_id`s | **Reported only** — indicates a bug in idempotency handling, never auto-repaired |
| Orphaned reservations (message row missing) | **Reported only** (the FK `cascadeOnDelete` should make this impossible outside of manual data surgery) |

## SQLite locking limitations (tests)

The test suite runs against SQLite `:memory:` (see `phpunit.xml`). SQLite:

- Does not support true row-level locking; `lockForUpdate()` degrades to database-level locking behavior with `busy_timeout`-style waiting rather than genuine concurrent row locks.
- Cannot run truly parallel connections against the same `:memory:` database (each Pest process/test typically gets its own connection/schema in this suite).

Because of this, **true concurrent-request race tests are not meaningful in this suite** and are not included as multi-process tests. Instead, `tests/Feature/OutboundUsageTest.php` covers concurrency-adjacent correctness by:

- Reserving a second message while a first reservation is still `reserved` (uncommitted) and asserting the *outstanding reservation* is correctly included in the allowance check (sequential, single-connection, but exercises the same code path a real race would).
- Verifying no double-reserve happens on idempotent replay and on a simulated duplicate reservation attempt.

Production deployments (MySQL/Postgres) get genuine row-level locking via `lockForUpdate()` on both `subscription_usage` and `outbound_usage_reservations`.

## Config

`config/outbound_usage.php` (see file for full docs) / `.env.example`:

```env
OUTBOUND_USAGE_METERING_ENABLED=true
OUTBOUND_USAGE_RESERVATION_TTL_SECONDS=3600
OUTBOUND_USAGE_DEFAULT_RESET_PERIOD=monthly
OUTBOUND_USAGE_RECONCILE_BATCH_SIZE=200
OUTBOUND_USAGE_FREE_MESSAGES_PER_PERIOD=200
OUTBOUND_USAGE_FREE_RECIPIENTS_PER_PERIOD=500
OUTBOUND_USAGE_FREE_ATTACHMENT_BYTES_PER_PERIOD=104857600
OUTBOUND_USAGE_FREE_RESET_PERIOD=monthly
```

## Scope explicitly excluded

No payment gateway integration, no overage invoices, no metered billing export, no automatic plan upgrades, no marketing bundles, and no abuse-limit disclosure — all per Prompt 618 scope restrictions. `OutboundRateLimiter` (abuse) is untouched and remains the sole source of truth for abuse thresholds.
