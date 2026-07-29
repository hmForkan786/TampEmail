# Outbound Mail Deployment Checklist

Pre-production checklist for outbound mail (Prompt 655). Complements `OUTBOUND_WORKER_DEPLOYMENT.md` and `OUTBOUND_RELEASE_CANDIDATE_CHECKLIST.md`.

## Prerequisites

- [ ] Migrations applied (outbound messages, attempts, provider events, suppressions, usage reservations, sender profiles, notifications, domain auth, abuse blocks, canaries, retention)
- [ ] Commercial plan features seeded (`send_email`, `reply_email`, `forward_email`, outbound schedule/sender profile entitlements, usage meters)
- [ ] API scopes `outbound_messages:read` / `outbound_messages:write` enabled
- [ ] Dedicated outbound mailer credentials in env (never logged)

## Fail-closed defaults (must explicitly open)

```env
OUTBOUND_ENABLED=false
OUTBOUND_TRANSPORT=unavailable
OUTBOUND_EMERGENCY_STOP=true
OUTBOUND_ROLLOUT_MODE=disabled
OUTBOUND_ROLLOUT_PERCENT=0
```

Production enablement sequence:

1. Configure SMTP / mailer (`OUTBOUND_MAILER`, host, port, TLS, auth)
2. Set `OUTBOUND_TRANSPORT` to the live transport key
3. Confirm SNS/HMAC webhook secrets and endpoint
4. Start workers (delivery, events, notifications)
5. Enable scheduler
6. Set `OUTBOUND_ENABLED=true`
7. Roll out: `canary` → `percentage` → `enabled`
8. Clear emergency stop only when ready

## Queue / workers

- [ ] `outbound-delivery` worker(s) with documented tries/timeout/backoff
- [ ] `outbound-events` worker(s) for provider callbacks
- [ ] `notifications` worker for outbound notification email
- [ ] Optional `outbound-maintenance` for prune/reconcile heavy work
- [ ] `OutboundWorkerConfigValidator` / timeout ordering satisfied
- [ ] Supervisor examples deployed from `deploy/supervisor/`

## Scheduler

- [ ] `php artisan schedule:run` every minute
- [ ] `outbound:dispatch-scheduled` every minute (`withoutOverlapping`)
- [ ] `outbound:reconcile-stale-sending` every 5 minutes
- [ ] `outbound:reconcile-unmatched-events` every 15 minutes
- [ ] `outbound:reconcile-events` every 15 minutes
- [ ] `outbound:reconcile-usage` every 15 minutes
- [ ] `outbound:verify-domains` hourly
- [ ] `outbound:prune --confirm` daily when `outbound_retention.cleanup_enabled=true`

Verify:

```bash
php artisan schedule:list
```

## Provider webhooks

- [ ] `POST /api/v1/webhooks/outbound/{provider}` reachable over TLS
- [ ] SES SNS signature verification / generic HMAC configured
- [ ] Subscription confirmation via `outbound:confirm-ses-subscription` (no auto-confirm)
- [ ] Rate limits and body size limits reviewed

## Domain authentication

- [ ] SPF / DKIM / DMARC policy understood
- [ ] Domains marked `outbound_enabled` only when verified
- [ ] `outbound:verify-domains` healthy

## Commercial / usage

- [ ] Metering flags in `config/outbound_usage.php` reviewed
- [ ] Free plan restrictions documented
- [ ] Grace / downgrade / cancellation behavior understood
- [ ] Usage summary API smoke-tested

## Retention

- [ ] `OUTBOUND_RETENTION_CLEANUP_ENABLED` set intentionally
- [ ] Notification retention days configured
- [ ] Hold create/release admin path known

## API / UI smoke test

1. Create draft → update with version → submit
2. Direct send with idempotency key (replay returns same message)
3. List / detail / timeline owner-scoped
4. Cancel queued message
5. Schedule + `dispatch-scheduled` / send-now
6. Provider delivered event (verified) → `delivered`
7. Bounce/complaint → suppression → subsequent send 422
8. Notification list / dismiss / preferences
9. Foreign owner access → 404

## Security

- [ ] Owner isolation verified
- [ ] Header injection / HTML sanitization covered by regression
- [ ] Attachment download owner-only
- [ ] Secrets only in env; not in logs or API payloads
- [ ] Emergency stop tested

## Monitoring

- [ ] `outbound:status` baseline captured
- [ ] Launch readiness checked before traffic
- [ ] Alert on reconcile/prune command failures and queue depth growth
- [ ] Bounce/complaint rate thresholds understood

## Config / route cache

```bash
php artisan config:cache
php artisan route:cache
php artisan config:clear   # after local verification if needed
```

- [ ] Config cache succeeds with production env
- [ ] Route cache succeeds
- [ ] `git diff --check` clean on release branch

## Post-deploy verification

```bash
php artisan outbound:launch-readiness
php artisan outbound:status --json
php artisan outbound:canary-send   # if canary mode
```

- [ ] Canary accepted → sent → (optional) delivered event
- [ ] No unexpected suppressions or usage double-counts
- [ ] Workers and scheduler heartbeats healthy
