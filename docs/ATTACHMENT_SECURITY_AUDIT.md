# Attachment & ClamAV Security Audit (Prompt 656)

Security-first production audit of the inbound attachment pipeline from ingestion through ClamAV scan, quarantine, authorized download, retention, and deletion. Architecture unchanged: no new antivirus engine, cloud malware service, public storage, browser-side scanning, or schema redesign.

**Audit date:** 2026-07-29  
**Branch:** `feature/billing-payments`  
**Baseline HEAD:** `4796afc` (after Prompt 655)  
**Final decision:** **PASS** (with accepted limitations)

## Frozen architecture (verified unchanged)

```text
Attachment
  → Private Storage
  → ClamAV Scan
  → Scan Status
  → Visibility Policy
  → Authorized Download
```

No new antivirus provider, public attachment URLs, or browser-authoritative scan path introduced.

## Authoritative components

| Concern | Implementation |
| --- | --- |
| Ingest | `IngestInboundEmailAction`, `InboundMimeParser`, `ParsedAttachment` |
| Storage | `attachments` disk (`storage/app/private/attachments`, `visibility=private`) |
| Scan job | `ScanInboundAttachmentJob` on `attachment-scanning` |
| Scanner | `ClamAvAttachmentScanner` (INSTREAM) / `DisabledAttachmentScanner` |
| State | `AttachmentScanStatus`, `AttachmentScanService` |
| Visibility | `AttachmentVisibilityPolicy` + `StreamsSafeAttachments` |
| Download | `AttachmentDownloadController` (API); outbound download controllers |
| Quarantine admin | Filament Quarantined Attachments + purge/rescan actions |
| Health/ops | `attachments:scanner-health`, `live-check`, `status` |
| Retention | `InboundRetentionService` via `inbound:cleanup` |

Related: `ATTACHMENT_SCANNING_CONTRACT.md`, `CLAMAV_INTEGRATION_TESTING.md`, `ATTACHMENT_DOWNLOAD_API_CHANGE_MANIFEST.md`.

---

## 1. Attachment ingestion — PASS

| Check | Result |
| --- | --- |
| Upload source | Inbound MIME only (no public upload API) |
| MIME extraction | From MIME part `Content-Type` at parse time |
| Filename | `basename()` + 255 truncate at create |
| Path | Opaque `quarantine/{emailId}/{32hex}` — never original filename |
| Size / count | `max_bytes` 25MB, `max_count` 20, `max_total_bytes` 50MB |
| Transactional | Attachment rows + puts inside ingest transaction; rollback deletes stored paths |

---

## 2. Private storage — PASS

- Disk `attachments`: local private root under `storage/app/private/attachments`
- Visibility `private`; no public disk URL for attachments
- Platform disk name from `platform.storage.attachments_disk`
- Owner isolation via email/inbox ownership on download
- Temp probes cleaned by live-check; ingest failure rolls back orphan puts
- No dedicated orphan sweeper for stranded files without DB rows (accepted limitation)

**Verified:** no attachment becomes publicly reachable via storage config.

---

## 3. Malware scanning — PASS (authoritative)

- Dispatch: `ScanInboundAttachmentJob` (`ShouldBeUnique`, queue `attachment-scanning`)
- Protocol: ClamAV `zINSTREAM` chunked stream; size-capped before connect
- Timeout / retry: config timeouts; backoff `60,300,900`; max attempts 1–10 (default 3)
- Unavailable / timeout / write / error → retryable then terminal `failed`
- Disabled backend → `skipped` (never `clean`)
- Infected → terminal `infected`, `is_safe=false`
- Binding: only `clamav` or disabled (`bootstrap/app.php`)

**Authoritative rule:** only an explicit scanner `clean` verdict may set `scan_status=clean` and `is_safe=true`.

---

## 4. Scan state machine — PASS (actual)

```text
ingest → pending
  ├─ backend=disabled → skipped (terminal, undownloadable)
  └─ claim → scanning
        ├─ clean (is_safe=true)
        ├─ infected (is_safe=false)
        └─ failed / retry → pending → … → failed (retry_exhausted)
```

There is **no** `quarantined` enum value. Logical quarantine = `infected` \| `failed` (`AttachmentVisibilityPolicy::isQuarantined`).

Terminal: `clean`, `infected`, `failed`, `skipped`. Manual rescan: `failed` → `pending` only (admin).

---

## 5. Quarantine — PASS

- Location: same private disk under `quarantine/…` (logical, not separate public store)
- Owner download denied for infected/failed/pending/scanning/skipped
- Admin: Filament list/view; no owner download action; paths sanitized in UI
- Purge: `PermanentlyDeleteQuarantinedAttachmentAction` (hold-aware, path must start `quarantine/`)
- Audit: `attachment.quarantine_purged`, `attachment.rescan_requested`, `attachment.scan_*`

---

## 6. Download authorization — PASS

```text
Only scan_status=clean AND is_safe=true
  AND private disk match
  AND safe path
  AND file exists
  AND owned visible email/inbox
→ Authorized download
```

Otherwise **404** (no status leakage). Covers unauthorized owner, pending/infected/failed/skipped, missing file, expired/deleted inbox, path traversal, disk mismatch. Range requests → 416.

Inbound holds block retention delete/purge, not clean-owner download (retention semantics; accepted).

---

## 7. MIME validation — PASS (as implemented)

- Ingest trusts MIME part Content-Type (no magic-byte sniffing in app)
- Download: MIME regex whitelist else `application/octet-stream`; `X-Content-Type-Options: nosniff`
- Extension stored from filename; never used as malware evidence
- Archive deep inspection / zip-bomb bounds / nested archive policy: **not implemented in app** (contract aspirational; excluded from Prompt 656 scope)

---

## 8. Filename security — PASS

- Ingest: `basename` only; opaque storage key
- Download: control chars / quotes / path separators stripped; length capped; no CRLF in Content-Disposition
- Path guards: no `..`, null bytes, absolute / drive paths

---

## 9. Storage integrity — PASS (with limitations)

- SHA-256 at parse; stored on row
- Transactional create + rollback cleanup on storage failure: verified
- Missing quarantine file → failed, no unsafe download
- Checksum re-verify against bytes at scan time / clean-checksum skip-rescan / ingest dedupe: **not implemented** (accepted limitations; rescanning is fail-safe)

---

## 10. ClamAV health — PASS

| Command | Role |
| --- | --- |
| `attachments:scanner-health` | PING / config readiness |
| `attachments:scanner-live-check` | In-memory clean + EICAR probes (rate-limited) |
| `attachments:scanner-status` | Counts, queue, quarantine overview |

Fail-closed when daemon unreachable. Default backend `disabled` until operators enable ClamAV.

---

## 11. Failure recovery — PASS

Covered by feature tests: scanner offline, timeout retry, uniqueness, corrupted/missing file, storage rollback, terminal isolation across attachments, job failed handler.

---

## 12. Retention — PASS

- `inbound:cleanup` deletes eligible attachment bytes under `quarantine/` when enabled
- Skips `pending`/`scanning`; respects inbound holds
- Parent email deletion cascades soft-delete; purge removes bytes
- Per-status retention day knobs in older docs are **not** separately applied — cleanup uses inbound email retention window (accepted limitation)

---

## 13. API — PASS

- Metadata via email/attachment resources (sanitized scan status for admins/audit)
- Download: clean-only stream; unsafe → 404
- Quarantine admin Filament only

---

## 14. Security — PASS

Owner isolation, clean-only authZ, path traversal blocks, header/filename sanitization, nosniff, private disk, safe logging (no bytes/paths in API logs), audit events. No SSRF in ClamAV path (configured private host/socket only).

---

## 15–17. Operations / monitoring / scheduler

See `CLAMAV_OPERATIONS_RUNBOOK.md` and `ATTACHMENT_DEPLOYMENT_CHECKLIST.md`.

Monitoring: pending/clean/infected/failed counts, oldest pending age, ops thresholds, Filament Attachment Scanner Ops. Scheduler: `inbound:cleanup` (when enabled) — no separate attachment-only prune command.

---

## Regression evidence (Prompt 656)

| Group | Result |
| --- | --- |
| Attachment ingest/scan/policy/quarantine/rollback/retry/job | passed |
| Download (owner, unauthorized, unsafe states, dual gate, filename/disk) | passed (+ expanded) |
| ClamAV unit + health | passed |
| Integration EICAR/clean | skipped unless `RUN_CLAMAV_TESTS=1` |
| Broad `--filter=Attachment\|ClamAv\|Scanner` | **96 passed, 2 skipped** |

### Tooling

| Check | Result |
| --- | --- |
| Pint (changed test) | OK |
| PHPStan changed paths | Pest baseline noise on tests; no production code changes |
| Full PHPStan | Pre-existing baseline errors |
| Config / route cache | OK |
| Scheduler | `inbound:cleanup` gated; attachment scan is queue-driven |
| `git diff --check` | Clean on audit artifacts |

---

## Accepted limitations

| Limitation | Classification |
| --- | --- |
| No magic-byte MIME / archive expansion / zip-bomb bounds in app | accepted (scope exclusion) |
| No checksum re-verify or clean-checksum skip-rescan | accepted |
| No orphan file sweeper beyond ingest rollback | accepted |
| Clean bytes remain under `quarantine/` path prefix | accepted (isolation OK) |
| Inbound holds do not block clean downloads | accepted (retention semantics) |
| Per-status retention day env unused | accepted |
| Default `ATTACHMENT_SCANNER_BACKEND=disabled` | by design fail-closed |
| Live ClamAV EICAR requires `RUN_CLAMAV_TESTS=1` + daemon | ops/test env |

None create public storage exposure, owner isolation failure, download of unscanned/infected content, or fail-open `clean` without scanner verdict.

## Final decision

**Attachment & ClamAV security production ready: YES**

Fail-closed private storage and clean-only downloads are authoritative. ClamAV INSTREAM scanning, quarantine admin, health commands, and regressions verify the frozen architecture. Production enablement still requires a reachable `clamd` and `attachment-scanning` workers before flipping `ATTACHMENT_SCANNER_BACKEND=clamav`.
