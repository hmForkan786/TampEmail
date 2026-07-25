# Outbound Provider Portability and Secondary Provider Failover Foundation (Prompt 619)

Status: foundation only. A secondary provider identity can be configured safely, but **automatic cross-provider retry is not implemented**. See [Limitations](#limitations-read-this-first) below.

Cross-links: [`docs/OUTBOUND_EMAIL_CONTRACT.md`](./OUTBOUND_EMAIL_CONTRACT.md) (overall outbound architecture), [`docs/OUTBOUND_USAGE_ACCOUNTING.md`](./OUTBOUND_USAGE_ACCOUNTING.md) (quota accounting, unaffected by manual provider retry — see [Usage accounting](#usage-accounting)).

## Limitations (read this first)

**There is no automatic cross-provider failover in this codebase.** `DeliverOutboundMessageJob` always sends through the primary provider and only ever retries the *same* provider on temporary failure, exactly as before this prompt. `OUTBOUND_FAILOVER_ENABLED` exists as a defense-in-depth flag for future code paths to additionally check — today nothing reads it to trigger an automatic resend. This is a deliberate scope decision, not an oversight:

> A message with ambiguous primary-provider acceptance must never be resent automatically through a secondary provider, and duplicate-safety cannot be proven for most failure shapes (timeouts, connection resets, "accepted but persistence failed", etc.) with the instrumentation that exists today.

The only way to resend a message through a different provider is the audited, platform-admin-only `RetryOutboundMessageWithProviderAction`, and even that is refused for anything but a narrow set of provably-safe pre-acceptance failures (see [Failover eligibility](#failover-eligibility)).

## Why "portability" instead of "failover"

The goal of this prompt is to make the outbound architecture **not coupled to one vendor identity**, so that:

- A second provider's credentials, capabilities, and readiness can be modeled and observed without touching messages, webhooks, or delivery-event correlation for the primary provider.
- Historical data (delivery attempts, domain-authentication records) stays attributable to the provider that was actually used, even after configuration changes.
- A human (platform admin) has a safe, audited, narrowly-scoped way to move a specific failed message to a different provider when — and only when — it is provably safe to do so.

It intentionally does **not** implement load balancing, automatic failover, provider selection by the sender, or any traffic-shaping across providers.

## Architecture

### `OutboundProviderRegistry`

`app/Services/Outbound/OutboundProviderRegistry.php` is the single source of truth for provider identity:

| Method | Purpose |
|---|---|
| `supportedProviders()` | `['generic', 'ses']` — the only provider identities that exist anywhere in the codebase |
| `isSupported(string)` / `assertSupported(string)` | Fail-closed check; an unsupported name is never silently treated as generic |
| `primaryProvider()` | The provider used for all normal sending (falls back to `generic` if misconfigured) |
| `secondaryProvider()` | The optional second provider identity, or `null` if unset/unsupported/duplicate-of-primary |
| `failoverEnabled()` | `OUTBOUND_FAILOVER_ENABLED` AND a usable secondary — read by nothing that auto-resends today |
| `configErrors()` | Secret-free list of configuration problems (see [Configuration](#configuration)) |
| `resolveTransport(string $provider)` | Returns a transport tagged with that provider's identity (see [One live transport driver](#one-live-transport-driver-not-two)) |
| `resolveParser(string $provider)` | Delegates to `OutboundProviderEventParserRegistry` |
| `capabilities(string $provider)` | Static, secret-free capability flags (`smtp_submission`, `delivery_webhooks`, `bounce_events`, `complaint_events`, `safe_connectivity_probe`, `provider_message_id`) |
| `readiness(string $provider)` | Live, secret-free readiness: parser resolves, webhook secret/topic present, shared transport config valid, verified-domain count for that provider |

### One live transport driver, not two

Only one live outbound transport driver is implemented (`config('outbound.transport')`: `smtp` / `mail` / `array`). Configuring a secondary *provider* does **not** mean a second, independently-configured SMTP credential set exists. `OutboundProviderRegistry::resolveTransport($provider)` tags the same underlying transport with the requested provider's *identity* — this is enough to correctly attribute attempts and route webhooks, but it means:

- A "secondary provider" today mostly represents an already-live vendor path reachable through the shared SMTP configuration (e.g. an SES SMTP relay that is also your primary), or a vendor identity you are preparing to cut over to.
- Implementing a second, fully independent live adapter (e.g. a distinct HTTP API client) is out of scope for this prompt (see the original prompt's scope restrictions: at most one new *live* provider adapter total).

### Configuration

`config/outbound.php`:

```
OUTBOUND_PRIMARY_PROVIDER=generic   # falls back to OUTBOUND_PROVIDER when unset (back-compat)
OUTBOUND_SECONDARY_PROVIDER=        # optional; must differ from primary and be supported
OUTBOUND_FAILOVER_ENABLED=false     # defense-in-depth flag only; nothing auto-resends on it today
```

`outbound.provider` (the pre-existing config key) is kept as a back-compat alias: every existing call site that reads `config('outbound.provider')` automatically gets primary-provider semantics with zero code changes, because the config file resolves it from the same `OUTBOUND_PRIMARY_PROVIDER` / `OUTBOUND_PROVIDER` environment pair.

`OutboundProviderRegistry::configErrors()` fails closed on:

| Error code | Meaning |
|---|---|
| `primary_provider_required` | Outbound enabled but no primary provider configured |
| `primary_provider_unsupported` | Primary provider name is not `generic`/`ses` |
| `secondary_provider_unsupported` | Secondary provider name is set but not `generic`/`ses` |
| `secondary_provider_duplicates_primary` | Secondary equals primary — never a valid distinct identity |
| `failover_enabled_without_usable_secondary` | `OUTBOUND_FAILOVER_ENABLED=true` but no valid secondary is configured |

These errors are folded into `OutboundLaunchConfigValidator::validate()['errors']` (and therefore block launch readiness) regardless of rollout mode — a broken provider configuration is never considered safe to ship live traffic against, independent of whether failover is actually used.

### Attempt provider identity

`outbound_delivery_attempts` gained two columns (migration `2026_07_25_270000_add_provider_portability_columns_to_outbound_delivery_attempts`):

- `provider` (nullable string, indexed) — the vendor identity **selected for that specific attempt at the time it started**, captured once by `OutboundDeliveryAttemptRecorder::start()` and never re-derived from live config later. Historical attempts remain correctly attributed even after `OUTBOUND_PRIMARY_PROVIDER` / `OUTBOUND_SECONDARY_PROVIDER` change. Backfilled from the parent message's `provider` (or `'unknown'`) for pre-existing rows.
- `failover_reason_code` (nullable string) — a safe, sanitized code recording why a cross-provider retry was (or was not) permitted for that attempt. Set once at `start()` from the eligibility evaluation and preserved through `complete()` (never silently wiped by a later `complete()` call that does not pass its own code).

`DeliverOutboundMessageJob` always passes `OutboundProviderRegistry::primaryProvider()` here, because it never sends through anything else.

### Failover eligibility

`app/Services/Outbound/OutboundFailoverEligibility.php` is a pure policy class (no side effects, no sending) with one job: decide whether a **already-`Failed`** message may safely be retried through a different provider.

**Eligible** — only safe, pre-acceptance failures where no byte could possibly have reached the remote provider:

- `invalid_config`, `invalid_mailer`, `invalid_transport`, `transport_unavailable` — local/transport-selection problems caught before any connection attempt
- `dns_failure` — SMTP host DNS resolution failed; by definition no TCP connection was ever attempted

**Never eligible** (always blocked), regardless of failure code:

- The message's `reconciliation_flagged_at` is set (stale-sending reconciliation already flagged this as ambiguous)
- The latest delivery attempt has `ambiguous = true` (the worker invoked the transport and died before the outcome could be persisted — the transport may have already accepted it)
- The latest attempt is not yet terminal
- Any other failure code, including: timeouts, connection resets, rate limiting, and anything else that happens *after* a connection may have been established (`timeout`, `smtp_4xx`, `smtp_5xx`, `transport_temporary`, `rate_limit`); permanent transport rejections and invalid-recipient (`invalid_recipient`, `message_too_large`); authentication failures (`credentials_rejected`, `tls_configuration` — these prove a connection *was* made); domain-authentication failures; suppression/abuse blocks; manual cancellation; retry exhaustion (`stale_sending_attempts_exhausted`)

This list is intentionally conservative. Some of these codes (timeouts, `smtp_4xx`) are already retried through the *same* provider by the normal same-provider retry loop; they are excluded from cross-provider eligibility specifically because current instrumentation cannot prove the remote provider did not already queue the message.

### Manual provider retry

`app/Actions/Outbound/RetryOutboundMessageWithProviderAction.php` is the **only** code path in the application that can send a message through a provider other than the one it originally failed with. It is platform-admin-only and performs, in order:

1. Actor is a platform admin (`User::isPlatformAdmin()`)
2. Message exists and is currently `Failed`
3. Target provider is in the allowlist `{primary, secondary}` only — never an arbitrary string
4. `OutboundFailoverEligibility::evaluate()` passes (see above)
5. `OutboundProviderRegistry::readiness($targetProvider)['ready']` is true
6. The inbox's domain is active and outbound-enabled
7. `OutboundDomainAuthenticationService::isDomainReady($domain, $targetProvider)` is true — **the target provider's own domain-authentication record**, independent of the primary's. Primary DKIM/SPF being verified never implies the secondary is ready.
8. User is active
9. Outbound feature flag enabled and the operation's plan entitlement is present
10. `OutboundRateLimiter::assertNotBlocked()` — the user is not currently abuse-blocked/suspended
11. `OutboundSuppressionService::assertRecipientsAllowed()` — no recipient is suppressed
12. `OutboundLaunchControlService::assertRolloutEligible()` — emergency stop is not active and the rollout mode allows this user/inbox

Any failed check denies the retry, leaves the message and its attempt history completely untouched, and writes `outbound.manual_provider_retry_blocked` with the specific `reason_code`. No state is ever partially mutated on denial.

If every check passes, the action performs **one bounded delivery attempt** synchronously (not re-queued through `DeliverOutboundMessageJob`, precisely so this path never touches the job's same-provider retry loop): it claims the message, starts a new `OutboundDeliveryAttempt` row tagged with the target provider and the eligibility reason code, calls `OutboundProviderRegistry::resolveTransport($targetProvider)->send()`, and records the outcome. Audit events:

- `outbound.manual_provider_retry_requested` — written once the retry is permitted and the attempt begins
- `outbound.manual_provider_retry_succeeded` — transport accepted the message
- `outbound.manual_provider_retry_failed` — transport rejected/temp-failed it (the message returns to `Failed`; a further manual retry can be requested again subject to the same eligibility check against the new attempt)
- `outbound.manual_provider_retry_blocked` — the retry was denied (see reason codes above)

This is exposed on the **Outbound Email** Filament ops page (`app/Filament/Admin/Pages/OutboundEmailOps.php`) as a small admin-only form (message ID + target provider), in addition to being directly callable as an action for any admin API surface.

### Domain readiness per provider

`OutboundDomainAuthenticationService` already stored one row per `(domain_id, provider)`; this prompt extends the runtime API to make provider-specific checks first-class:

- `expectedRecordsFor(Domain, ?provider)`, `ensureRecord(Domain, ?provider)`, `verify(Domain, ?provider)` already accepted an explicit provider
- `assertDomainReady(Domain, ?provider = null)` now accepts a named provider (previously always checked the global primary)
- `isDomainReady(Domain, ?provider = null): bool` — new non-throwing variant for ops/manual-retry call sites

When a secondary provider is configured, its domain-authentication readiness is completely independent of the primary's — verifying SPF/DKIM for the primary implies nothing about the secondary.

### Webhook routing and provider isolation

Webhooks already route by trusted `{provider}` path segment (`POST /api/v1/webhooks/outbound/{provider}`) into the registry-backed parser. This prompt tightens `OutboundProviderEventProcessor::resolveMessage()` so that **every** provider's events — including `generic`, which previously bypassed provider filtering entirely — only correlate to messages whose stored `provider` matches the event's provider (or an explicitly configured alias):

```php
$matches = OutboundMessage::query()
    ->whereIn('provider_message_id', $candidates)
    ->where(function ($inner) use ($data, $aliases): void {
        $inner->where('provider', $data->provider);
        if ($aliases !== []) {
            $inner->orWhereIn('provider', $aliases);
        }
    })
    ->lockForUpdate()
    ->get();
```

Previously, a `generic`-provider webhook event with no configured aliases matched **any** message by `provider_message_id`, regardless of that message's own `provider` column — meaning a secondary provider's webhook could, in principle, mutate a primary-attributed message's state via a `provider_message_id` collision (or vice versa). This is now closed for every provider identity, not just `ses`.

`OUTBOUND_SES_TRANSPORT_ALIASES` (comma-separated, empty by default) remains available as an explicit, audited **migration opt-in** for operators moving from a config where messages were tagged with the raw transport driver name (`smtp`) instead of the vendor identity (`ses`) before this prompt. A non-empty alias list is never a default — it must be deliberately configured.

### Operations monitoring

`OutboundOpsService::report()['providers']` (and the **Outbound Email** ops page) now surfaces:

- `primary_provider`, `secondary_provider`, `failover_enabled`
- `config_errors` (from `OutboundProviderRegistry::configErrors()`)
- `readiness` — per-provider secret-free readiness for every configured provider
- `attempts_by_provider_24h` — delivery attempt counts grouped by the `provider` column
- `failover` — `attempts_requested` / `succeeded` / `failed` / `blocked` counters sourced from the manual-retry audit events above. These start at zero on a fresh install and only move via the audited manual action; there is no automatic counter source because there is no automatic failover.

`OutboundLaunchReadinessService`'s `provider` check block gained `secondary_provider` / `secondary` (full readiness) / `failover_enabled`. Secondary readiness is informational only — it never gates the overall launch `status`, since nothing depends on the secondary being ready except the manual retry action, which checks it live at retry time.

## Usage accounting

Manual provider retry does **not** call into `OutboundUsageService` at all. Quota for the message was already permanently accounted for (`recordPermanentFailure`) when it first reached `Failed`, since every eligible pre-acceptance failure code is only reachable after the transport was actually invoked. A manual retry is a continuation of that same already-metered send attempt, not a new billable unit — see `docs/OUTBOUND_USAGE_ACCOUNTING.md`.

## Rollout strategy

1. Deploy with `OUTBOUND_SECONDARY_PROVIDER` unset (default). No behavior changes: `secondaryProvider()` returns `null`, `providers` ops metrics show one provider, manual retry's allowlist has only the primary.
2. When ready to prepare a secondary identity: set `OUTBOUND_SECONDARY_PROVIDER`, verify its domain-authentication record independently (`outbound:verify-domains`), and confirm `OutboundProviderRegistry::readiness($secondary)['ready']` before relying on it.
3. Use the manual retry action to validate the secondary path end-to-end on a small number of real failed messages that meet the strict eligibility bar — never on ambiguous or already-delivered messages (the action refuses these regardless).
4. Do **not** set `OUTBOUND_FAILOVER_ENABLED=true` expecting automatic behavior — it is a defense-in-depth flag with no automatic reader today. Leave it `false` unless/until an automatic path is built and proven duplicate-safe (tracked as future work, not part of this prompt).

## Explicitly out of scope

Per the original prompt's scope restrictions, none of the following exist:

- A second independently-configured live provider adapter (only one live transport driver exists; see [One live transport driver](#one-live-transport-driver-not-two))
- Automatic credential migration between providers
- Automatic resend of ambiguous messages, through any provider
- Random/weighted traffic balancing across providers
- Cost-based provider selection
- Marketing/campaign routing logic
- Exposing provider credentials in any UI
- Letting end users choose which provider sends their message
