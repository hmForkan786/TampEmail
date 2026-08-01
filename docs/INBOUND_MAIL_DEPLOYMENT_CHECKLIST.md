# Inbound Mail Deployment Checklist

Pre-production and release checklist for the signed webhook inbound pipeline.

## Prerequisites

- [ ] HTTPS terminated in front of application (webhook URL is HTTPS-only in production)
- [ ] `INBOUND_GENERIC_WEBHOOK_SECRET` set (strong random, not committed)
- [ ] `APP_ENV=production`, `APP_DEBUG=false`
- [ ] Queue connection configured (`QUEUE_CONNECTION`)
- [ ] `attachments` disk private and writable
- [ ] Database migrations applied

## Webhook configuration

- [ ] Provider posts to `POST /api/v1/inbound/webhook`
- [ ] Headers: `X-Inbound-Provider`, `X-Inbound-Timestamp`, `X-Inbound-Signature`, `X-Inbound-Message-Id`
- [ ] Signature: `HMAC-SHA256(provider + '.' + timestamp + '.' + raw_body, secret)`
- [ ] `Content-Type: application/json`
- [ ] Payload includes `recipient`; `raw_mime_payload` contains RFC 822 MIME
- [ ] Optional tuning:
  - `INBOUND_WEBHOOK_TIMESTAMP_SKEW_SECONDS` (default 300)
  - `INBOUND_WEBHOOK_MAX_BODY_BYTES` (default 10485760)
  - `INBOUND_WEBHOOK_RATE_LIMIT_PER_MINUTE` (default 60)

## Queue workers

- [ ] Worker consumes **`default`** queue (`ProcessInboundMessageJob`)
- [ ] Worker consumes **`attachment-scanning`** queue (`ScanInboundAttachmentJob`)
- [ ] Outbound queues on separate workers (isolation)
- [ ] Supervisor/systemd restarts workers on failure
- [ ] `php artisan queue:restart` after deploy

**Do not** deploy inbound without a worker on `default` — job is intentionally on default queue (see audit §8).

## Scheduler

- [ ] `php artisan schedule:run` every minute
- [ ] If `INBOUND_RETENTION_CLEANUP_ENABLED=true`: `inbound:cleanup --confirm` scheduled daily

## Storage permissions

- [ ] `storage/app/attachments` (or configured disk root) owned by web/worker user
- [ ] Not web-public; downloads go through authorized API only

## Attachment scanner

- [ ] `ATTACHMENTS_SCANNER_BACKEND` set (`clamav` or `disabled` for dev)
- [ ] If enabled: ClamAV reachable from workers
- [ ] `php artisan attachments:scanner-health` passes

## HTML sanitizer

- [ ] Default Symfony sanitizer config (no extra deploy step)
- [ ] Verify smoke test: inbound HTML with `<script>` stripped in stored body

## Rate limits and abuse

- [ ] Reverse proxy rate limit optional (app has per-provider+IP limit)
- [ ] `max_body_bytes` appropriate for expected message size

## Health and monitoring

- [ ] `php artisan inbound:health` in monitoring (alert on exit code 1)
- [ ] Alert on `queue:failed` growth for `ProcessInboundMessageJob`
- [ ] Log aggregation excludes webhook secrets

## Smoke test (post-deploy)

1. Send signed test webhook with unique `X-Inbound-Message-Id`
2. Confirm `202` response with `accepted: true`
3. Confirm job processed on `default` queue
4. Confirm email visible in inbox API for resolved recipient
5. If attachment included: status `pending` → `clean` (or `skipped` if scanner disabled)
6. Resend same message ID → no duplicate email

Example (adjust secret and recipient):

```bash
BODY='{"recipient":"user@your-domain.test","sender":"test@example.com","raw_mime_payload":"From: test@example.com\r\nTo: user@your-domain.test\r\nSubject: Smoke\r\nMessage-ID: <smoke@example.com>\r\n\r\nSmoke test"}'
TS=$(date +%s)
SIG=$(php -r "echo hash_hmac('sha256', 'generic.'.$argv[1].'.'.$argv[2], getenv('INBOUND_GENERIC_WEBHOOK_SECRET'));" "$TS" "$BODY")
curl -sS -X POST "https://your-app/api/v1/inbound/webhook" \
  -H "Content-Type: application/json" \
  -H "X-Inbound-Provider: generic" \
  -H "X-Inbound-Timestamp: $TS" \
  -H "X-Inbound-Signature: $SIG" \
  -H "X-Inbound-Message-Id: smoke-$(date +%s)" \
  -d "$BODY"
```

## Rollback

1. `php artisan queue:restart` after reverting code
2. In-flight jobs may fail once — provider retry with same message ID is safe
3. Do not rotate webhook secret without updating provider simultaneously
4. If parser package reverted, ensure `zbateson/mail-mime-parser` remains if inbound processing required

## Config / route cache (production)

```bash
php artisan config:cache
php artisan route:cache
```

Verify:

```bash
php artisan schedule:list
php artisan migrate:status
```
