# Temail production operations runbook

This runbook is fail-closed guidance for deploying and operating Temail. Replace placeholders through the deployment system; never put credentials, private hostnames, tokens, or absolute local paths in this document.

## Deployment assumptions

The application is deployed behind an HTTPS reverse proxy with a supervised PHP runtime, a supported database, a queue backend, private attachment storage, and exactly one scheduler strategy. The signed inbound webhook is the only implemented inbound boundary; native SMTP/LMTP ingress is not available.

## Required runtime and services

Provide PHP 8.2+ with the extensions required by Laravel 12, Composer-installed dependencies, a supported database, and the web server/runtime required by the deployment. Node/npm is required only when building frontend assets. Production also requires a queue connection, cache/lock store, private filesystem storage, and a process supervisor.

The default queue connection and cache store are database-backed. Redis may be selected through the existing queue and cache configuration when the deployment provides a compatible Redis service. Validate capacity and failover before production traffic.

## Environment and secrets

Load environment values from an approved secret manager or protected deployment mechanism. Keep application keys, API-key material, provider webhook secrets, database credentials, queue credentials, and scanner credentials out of source control and logs. Keep debug mode disabled. Configure trusted proxies and HTTPS behavior consistently with the reverse proxy.

## Migration deployment sequence

1. Build and review the release artifact.
2. Run the repository's preflight/readiness checks appropriate to the environment.
3. Run `php artisan migrate --force` against the intended database.
4. Warm/reload the application runtime and workers under supervision.
5. Verify process and inbound health before accepting traffic.

Do not run destructive refresh, rollback, or seed operations against production without an approved change procedure.

## Queue worker deployment

Deploy supervised queue workers using the configured queue connection and bounded process settings. Workers must restart on release, respect configured timeout/tries limits, and expose fresh heartbeats. Local `composer dev` uses `php artisan queue:listen --tries=1 --timeout=0`; production must use the deployment's reviewed worker configuration rather than copying local development settings.

Check readiness with:

```text
php artisan processes:health --json
```

Treat stale worker heartbeats, unavailable queue connections, failed jobs, or incompatible lock stores as degraded/failed conditions requiring action.

## Scheduler deployment

Run exactly one scheduler strategy. The application schedules the process heartbeat and optional retention/expiration tasks through Laravel scheduling. The scheduler must remain supervised and must produce fresh heartbeat records.

Verify with:

```text
php artisan processes:health --json
```

Do not run overlapping scheduler strategies unless explicitly reviewed; duplicate schedulers can duplicate maintenance work.

## Heartbeat expectations

Worker and scheduler heartbeats are operational evidence, not proof that every workload is healthy. Monitor freshness, status, failed jobs, queue backlog, and scheduler state. A stale heartbeat is degraded or failed and must not be hidden by a generic healthy fallback.

## Signed webhook configuration

Configure provider-specific signing secrets and the approved provider identity through protected environment configuration. The endpoint is `POST /api/v1/inbound/webhook`; it validates provider, timestamp, signature, message ID, content type, recipient, and bounded body size. Keep the reverse proxy configured for HTTPS, request-size limits, and forwarding headers appropriate to the deployment.

The authoritative response and authentication contract is [`API_REFERENCE.md`](API_REFERENCE.md).

## Reverse proxy and HTTPS

Terminate TLS with an approved certificate and forward HTTPS-aware headers correctly. Restrict request body size and timeout to the webhook contract, avoid buffering sensitive payloads into public logs, and ensure only intended public routes are internet-facing. Administrative and health surfaces require their approved access controls.

## Storage and private attachments

Attachment storage must be private and accessible only through the owner-authorized download path or approved scanner process. Do not expose storage paths, raw MIME, attachment bytes, or scanner socket details in API responses, logs, metrics, or incident tickets. Backups and retention controls must preserve legal holds.

## ClamAV enablement gate

ClamAV is disabled by default. Enable it only when all of the following are true:

1. The approved daemon is reachable from the application runtime.
2. `php artisan attachments:scanner-health --json` reports a green/healthy scanner state.
3. The ClamAV integration suite passes against the intended daemon.
4. Retryable unavailable outcomes and terminal infected/permanent-failure outcomes have been verified.
5. Scanner failures cannot silently bypass scanning or mark attachments clean.

Use [`CLAMAV_INTEGRATION_TESTING.md`](CLAMAV_INTEGRATION_TESTING.md) for local and CI setup. An unavailable scanner is never a clean result; it remains pending/retryable or becomes a deterministic terminal failure after bounded exhaustion.

## Health-check commands

Run the confirmed application checks:

```text
php artisan processes:health --json
php artisan inbound:health
php artisan attachments:scanner-health --json
```

The process command covers queue and scheduler readiness. The inbound command reports safe lifecycle metrics. The scanner command checks readiness without scanning an attachment. Non-healthy results require investigation.

## Inbound health interpretation

`healthy` means the relevant health service completed its checks without a breached threshold. `degraded` means the service is available but a threshold or dependency condition needs attention. `failed` means the check or required dependency is unavailable. Never interpret a failed or disabled scanner as a clean attachment outcome.

## Logs and metrics

Review safe status, breach names, counters, latency aggregates, queue backlog, failed jobs, retry exhaustion, and heartbeat freshness. Do not log or export message bodies, raw headers, addresses, credentials, attachment bytes, scanner output, or unbounded attachment identifiers. Metrics are operational signals and do not replace state-transition records.

## Retry and replay operations

Retryable scanner transport/unavailable outcomes remain non-terminal and use bounded queue retry/backoff. Clean, infected, malformed, oversized, and other permanent rejection outcomes are terminal. Repeated terminal execution must not overwrite state or emit duplicate events.

Inbound failure replay is an administrative operational action subject to the existing authorization, audit, eligibility, and retention rules. Raw inbound MIME replay is not exposed as a public API. Confirm the target failure and current state before replaying; never replay from copied payload content in an incident channel.

## Retention and expiration

Review the existing retention and legal-hold policies before cleanup. Optional maintenance commands include the configured retention cleanup and inbox expiration schedules. Run only the existing commands with their required confirmation safeguards and approved change controls; never invent or use destructive purge commands from this document.

## Backup and restore

Back up the database, protected attachment storage, and required configuration through the approved platform process. Test restores in an isolated environment, verify ownership and legal-hold metadata, and confirm queue/lock state is rebuilt safely. A restore must not expose backup contents or bypass retention controls.

## Rollback procedure

1. Stop or pause traffic according to the incident plan.
2. Preserve logs, health output, failed-job evidence, and deployment identifiers.
3. Stop or drain workers safely before changing application code or schema dependencies.
4. Roll back to the last approved compatible release using the deployment system.
5. Do not reverse database migrations unless the migration owner has approved a reversible plan.
6. Restart exactly one scheduler strategy and the reviewed worker fleet.
7. Run process, inbound, and scanner health checks before resuming traffic.

## Incident checklist

- Record time, release, affected component, and current health status.
- Check `processes:health`, `inbound:health`, queue backlog, failed jobs, and scheduler freshness.
- Determine whether the issue is webhook validation, queue/lock infrastructure, storage, scanner availability, or retention.
- Preserve redacted evidence only; do not copy message bodies, headers, credentials, or attachment bytes.
- Pause scanner enablement or replay when state safety is uncertain.
- Escalate database, queue, storage, proxy, or scanner incidents to the responsible owner.
- Record remediation and verify no duplicate terminal transitions occurred.

## Release verification checklist

- Migrations completed successfully.
- HTTPS, proxy limits, and secret loading verified.
- Queue workers supervised and heartbeats fresh.
- Exactly one scheduler strategy active and heartbeat fresh.
- `php artisan processes:health --json` is healthy.
- `php artisan inbound:health` is healthy or an approved degraded state is documented.
- Scanner remains disabled unless the full ClamAV gate is green.
- Owner API and signed webhook smoke checks pass.
- Private attachment access and retention/legal-hold behavior verified.
- Backup/restore and rollback references are current.
