# Outbound Email Release-Candidate Checklist (Prompt 620)

Operator sign-off checklist for turning outbound email on in a new
environment or promoting a build to production. Each row names the
concrete command/config/table an operator can check; it does not
duplicate the design rationale already covered in the docs it links to.
Read `docs/OUTBOUND_EMAIL_CONTRACT.md`, `docs/OUTBOUND_WORKER_DEPLOYMENT.md`,
and `docs/OUTBOUND_LAUNCH_RUNBOOK.md` first.

This is an **operator checklist**, not new application behavior. It
introduces no code changes; every command/flag/table referenced below
already exists and is exercised by the automated test suite.

## 1. Migrations

- [ ] `php artisan migrate --force` run against the target database.
- [ ] `php artisan outbound:launch-readiness --json` → `checks.migrations.complete: true`
      and `checks.migrations.missing_tables: []`. A `blocked` status with
      `migrations_pending` means a required outbound table/column is missing.
- [ ] Tables expected: `outbound_messages`, `outbound_delivery_attempts`
      (with `provider` / `failover_reason_code` columns from the Prompt 619
      migration), `outbound_provider_events`, `outbound_recipient_suppressions`,
      `outbound_abuse_blocks`, `outbound_domain_authentications`,
      `outbound_launch_canaries`, `outbound_usage_reservations`.

## 2. Feature flags

- [ ] `OUTBOUND_ENABLED` — global kill switch (`config('outbound.enabled')`).
- [ ] `OUTBOUND_SEND_ENABLED` / `OUTBOUND_REPLY_ENABLED` / `OUTBOUND_FORWARD_ENABLED` —
      per-operation flags.
- [ ] Confirmed via `php artisan outbound:status --json` → `readiness.state`
      is not `unknown` once flags are intentionally on (`unknown` = feature
      disabled, the expected pre-launch state).

## 3. Plan entitlements

- [ ] `send_email`, `reply_email`, `forward_email` boolean features attached
      to the plans that should be allowed to send.
- [ ] Optional metered dimensions (unattached = unlimited, never a silent
      default — see `docs/OUTBOUND_USAGE_ACCOUNTING.md`):
      `outbound_messages_per_period`, `outbound_recipients_per_period`,
      `outbound_attachment_bytes_per_period`, `outbound_retention_days`.
- [ ] `php artisan outbound:launch-readiness --json` → `checks.plan_features.all_present: true`.

## 4. Outbound domains

- [ ] Target sending domain(s) have `is_active = true` and
      `outbound_enabled = true` (`domains` table).
- [ ] `php artisan outbound:verify-domains --domain=<domain>` run at least
      once for every domain that will send.

## 5. SPF / DKIM / DMARC

- [ ] SPF TXT published at the domain apex (SES: `v=spf1 include:amazonses.com ~all`,
      exactly one SPF record, no `+all`).
- [ ] DKIM CNAME/TXT published per `OUTBOUND_SES_DKIM_TOKENS`.
- [ ] DMARC TXT at `_dmarc.<domain>` (`p=quarantine`/`p=reject` recommended;
      `p=none`/missing is `degraded`, still send-eligible when
      `OUTBOUND_DOMAIN_AUTH_ALLOW_DEGRADED_DMARC=true`).
- [ ] `php artisan outbound:launch-readiness --json` → `checks.domain.verified_count >= 1`
      (or `degraded_count` acceptable per the table above). `failed_count > 0`
      for a domain you intend to use is a blocker — investigate before launch.

## 6. SMTP / provider credentials

- [ ] `OUTBOUND_TRANSPORT=smtp`, `OUTBOUND_MAILER=outbound`.
- [ ] `OUTBOUND_SMTP_HOST` / `PORT` / `USERNAME` / `PASSWORD` / `ENCRYPTION` set;
      `OUTBOUND_SMTP_VERIFY_PEER=true` in production.
- [ ] `OUTBOUND_PRIMARY_PROVIDER` (`generic` or `ses`) matches the actual
      transport in use. `OUTBOUND_SECONDARY_PROVIDER` only if a portability/
      failover-readiness identity is genuinely being prepared (see
      `docs/OUTBOUND_PROVIDER_PORTABILITY.md` — no automatic failover exists).
- [ ] `php artisan outbound:launch-readiness --json` → `checks.transport.valid: true`.
      Never inspect raw credentials from this or any other outbound command —
      all outbound readiness output is secret-free by design.

## 7. Webhook endpoint / secret

- [ ] Webhook URL reachable: `POST /api/v1/webhooks/outbound/{provider}`.
- [ ] Generic provider: `OUTBOUND_GENERIC_DELIVERY_WEBHOOK_SECRET` set (HMAC).
- [ ] SES provider: SNS topic subscribed to the webhook URL, subscription
      confirmed via `php artisan outbound:confirm-ses-subscription --from-cache`,
      optionally `OUTBOUND_SES_SNS_TOPIC_ARN` allowlisted.
- [ ] `php artisan outbound:launch-readiness --json` → `checks.provider.webhook_secret_present: true`.

## 8. Queues

- [ ] `QUEUE_OUTBOUND_DELIVERY`, `QUEUE_OUTBOUND_EVENTS`, `QUEUE_OUTBOUND_MAINTENANCE`
      set to three distinct names, all distinct from `QUEUE_ATTACHMENT_SCANNING`.
- [ ] `php artisan outbound:launch-readiness --json` → `checks.queue.status`
      is not `failed` (`invalid_queue_topology` / `invalid_worker_timeout_config`
      block launch).
- [ ] Timeout ordering holds: `OUTBOUND_SMTP_TIMEOUT` < `OUTBOUND_WORKER_TIMEOUT_SECONDS`
      < queue connection `retry_after` (>= 90s).

## 9. Workers

- [ ] Supervisor programs deployed from
      `deploy/supervisor/temail-outbound-delivery-worker.conf.example`,
      `temail-outbound-events-worker.conf.example`,
      `temail-outbound-maintenance-worker.conf.example` (reserved queue,
      safe to run against an always-empty queue today).
- [ ] `OUTBOUND_DELIVERY_WORKER_COUNT` / `OUTBOUND_EVENTS_WORKER_COUNT` /
      `OUTBOUND_MAINTENANCE_WORKER_COUNT` sized for expected volume.
- [ ] `php artisan outbound:status --json` → `queue.delivery.fresh_workers > 0`
      and `queue.events.fresh_workers > 0`.

## 10. Scheduler

- [ ] `php artisan schedule:list` includes: `outbound:verify-domains` (hourly),
      `outbound:reconcile-stale-sending` (every 5 minutes),
      `outbound:reconcile-unmatched-events` (every 15 minutes),
      `outbound:reconcile-events` (every 15 minutes),
      `outbound:reconcile-usage` (every 15 minutes, dry-run only — no
      `--confirm`, reports drift for operator review), and, only when
      `OUTBOUND_RETENTION_CLEANUP_ENABLED=true`, `outbound:prune --confirm` (daily).
- [ ] A scheduler process (`schedule:work` or a cron entry calling
      `schedule:run` every minute) is actually running against this
      deployment — `schedule:list` alone only proves the definitions exist.

## 11. Heartbeats

- [ ] `php artisan outbound:status --json` → `queue.maintenance.scheduler_fresh: true`.
- [ ] `php artisan processes:health --json` reports the generic
      queue/scheduler aggregate as healthy.
- [ ] No entries in `queue.issues` such as `delivery_worker_heartbeat_missing`,
      `events_worker_heartbeat_missing`, or `maintenance_scheduler_heartbeat_stale`.

## 12. Retry configuration

- [ ] `OUTBOUND_SEND_MAX_ATTEMPTS` (default 3) and `OUTBOUND_SEND_BACKOFF_SECONDS`
      (default `60,300,900`) reviewed for the target provider's rate limits.
- [ ] Confirmed bounded: `DeliverOutboundMessageJob::tries()` reads
      `outbound.send_max_attempts` directly — there is no unbounded-retry
      code path.

## 13. Suppression review

- [ ] Platform admin(s) assigned to review **Operations → Recipient
      Suppressions** on a regular cadence.
- [ ] `php artisan outbound:status --json` → `suppressions.active` /
      `blocked_sends_24h` baseline captured before launch for comparison.
- [ ] Confirmed elevated authorization is required to
      `OutboundSuppressionService::unsuppress()` a complaint/provider-sourced
      suppression (not self-service).

## 14. Abuse thresholds

- [ ] `OUTBOUND_ABUSE_BOUNCE_THRESHOLD`, `OUTBOUND_ABUSE_COMPLAINT_THRESHOLD`,
      `OUTBOUND_ABUSE_FAILED_THRESHOLD`, `OUTBOUND_ABUSE_SUPPRESSION_BLOCK_THRESHOLD`,
      `OUTBOUND_ABUSE_TEMP_BLOCK_HOURS` reviewed against expected sender volume.
- [ ] `OUTBOUND_ABUSE_FAIL_CLOSED=true` in production (deny-on-quota-backend-
      failure, not allow-on-failure).

## 15. Usage limits

- [ ] `OUTBOUND_USAGE_METERING_ENABLED` intentionally set (`true` to meter,
      `false` to fully bypass metering — a deliberate choice, not an
      oversight).
- [ ] Free-plan defaults (`OUTBOUND_USAGE_FREE_MESSAGES_PER_PERIOD`,
      `OUTBOUND_USAGE_FREE_RECIPIENTS_PER_PERIOD`,
      `OUTBOUND_USAGE_FREE_ATTACHMENT_BYTES_PER_PERIOD`,
      `OUTBOUND_USAGE_FREE_RESET_PERIOD`) reviewed — they only ever apply to
      a plan that explicitly attaches the corresponding feature (never a
      silent fallback for an unattached feature; see
      `docs/OUTBOUND_USAGE_ACCOUNTING.md`).
- [ ] `GET /api/v1/outbound-usage` spot-checked against a real test account.

## 16. Retention

- [ ] `OUTBOUND_RETENTION_CLEANUP_ENABLED` intentionally set. `false` means
      `outbound:prune` only ever reports counts, even with `--confirm`.
- [ ] `OUTBOUND_RETENTION_FREE_DAYS` / `OUTBOUND_RETENTION_PREMIUM_DAYS` /
      `OUTBOUND_ATTEMPT_RETENTION_DAYS` / `OUTBOUND_PROVIDER_EVENT_RETENTION_DAYS`
      reviewed against compliance requirements.
- [ ] `php artisan outbound:prune --dry-run` run at least once to sanity-check
      eligible counts before enabling `--confirm` on a schedule.

## 17. Rollout mode

- [ ] `OUTBOUND_ROLLOUT_MODE` starts at `disabled` (or `canary` for the
      first cohort) — never jump straight to `enabled`/`percentage` on a
      new deployment.
- [ ] `OUTBOUND_ROLLOUT_PERCENT` in `0-100`; `php artisan outbound:launch-readiness --json`
      → `checks.config_validation.errors` is empty (`rollout_percent_out_of_range`
      / `rollout_mode_unsupported` block launch).
- [ ] Any live rollout mode (`canary`/`percentage`/`enabled`) has a passing
      transport, ready queue topology, >= 1 verified domain, and a present
      webhook secret — `OutboundLaunchConfigValidator` blocks the change
      otherwise (`enabled_without_*` error codes).

## 18. Canaries

- [ ] At least one canary (`user`/`inbox`/`domain`/`api_key`) added via the
      **Outbound Launch Control** admin page or `OutboundCanaryService::add()`
      before switching `OUTBOUND_ROLLOUT_MODE=canary`.
- [ ] `canary_mode_without_canaries` absent from
      `outbound:launch-readiness --json` → `checks.config_validation.errors`.

## 19. Emergency stop

- [ ] `OUTBOUND_EMERGENCY_STOP=true` is the safe default for any environment
      that is not actively sending.
- [ ] Confirmed at least one platform admin knows how to flip it live from
      the **Outbound Launch Control** admin page without a redeploy.
- [ ] Understood: stopping never fails or deletes queued messages — it
      delays delivery-job execution
      (`OUTBOUND_EMERGENCY_STOP_RETRY_DELAY_SECONDS`) and blocks new
      sends/replies/forwards/manual retries only.

## 20. Launch readiness

- [ ] `php artisan outbound:launch-readiness --json` → `status: ready` before
      widening beyond canary.
- [ ] `blocked` or `degraded` statuses investigated and resolved (or
      explicitly accepted with a documented reason) before proceeding.
- [ ] Re-run after any credential, DNS, queue, or config change — readiness
      is not cached across deploys.

## 21. Monitoring ownership

- [ ] A named on-call owner reviews `php artisan outbound:status --json`
      and `php artisan outbound:launch-readiness --json` (or the
      **Outbound Email** / **Outbound Launch Control** admin pages) on a
      defined cadence.
- [ ] Alerting wired for `failed_jobs` growth on `outbound-delivery` /
      `outbound-events` / `outbound-maintenance` (e.g.
      `php artisan queue:monitor outbound-delivery,outbound-events,outbound-maintenance:100`)
      and for the pause recommendation
      (`OutboundLaunchRecommendationService::recommend()`) reading `hold`/`rollback`.

## 22. Rollback ownership

- [ ] A named owner is authorized and able to set
      `OUTBOUND_EMERGENCY_STOP=true` (or use the live admin override)
      immediately if bounce/complaint rates spike, provider auth starts
      failing, or the pause recommendation reads `rollback`.
- [ ] Rollback runbook reference: `docs/OUTBOUND_LAUNCH_RUNBOOK.md` §
      "Emergency stop" and § "Final launch checklist" step 10.
- [ ] Understood: rollback (emergency stop) is never automatic — an
      operator must act on the advisory recommendation explicitly.

## Sign-off

| Role | Name | Date | Notes |
|---|---|---|---|
| Engineering owner | | | |
| Operations / on-call owner | | | |
| Platform admin (rollout control) | | | |

## Related documentation

- `docs/OUTBOUND_EMAIL_CONTRACT.md` — architecture, lifecycle, authorization, abuse, privacy.
- `docs/OUTBOUND_WORKER_DEPLOYMENT.md` — queue topology, worker config, reconciliation, scheduler.
- `docs/OUTBOUND_LAUNCH_RUNBOOK.md` — rollout modes, canaries, emergency stop, pause recommendation.
- `docs/OUTBOUND_RETENTION_POLICY.md` — content redaction, hard-delete, legal hold.
- `docs/OUTBOUND_USAGE_ACCOUNTING.md` — metered entitlements, reservation lifecycle, reconciliation.
- `docs/OUTBOUND_PROVIDER_PORTABILITY.md` — secondary provider identity, manual cross-provider retry, explicit non-goals.
