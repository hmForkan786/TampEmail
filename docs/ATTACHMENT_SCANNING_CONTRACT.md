# Production Attachment Scanning Contract

Status: contract only. No scanner connection, quarantine flow, scan job, download endpoint, or attachment persistence change is implemented by this contract.

## Approved backend

The first supported production backend is ClamAV daemon (`clamd`) over a private network socket/TCP endpoint. An approved external scanning service may be added behind the same interface, but its authentication and transport adapter require separate approval. The default backend is `disabled`; disabled means attachments remain untrusted and must never be marked clean or downloadable.

## Configuration

| Key | Default | Rule |
|---|---:|---|
| `ATTACHMENT_SCANNER_BACKEND` | `disabled` | Only `disabled`, `clamav`, or approved `external` |
| `ATTACHMENT_CLAMAV_HOST` | `127.0.0.1` | Private scanner endpoint only |
| `ATTACHMENT_CLAMAV_PORT` | `3310` | TCP port 1–65535 |
| `ATTACHMENT_SCAN_TIMEOUT_SECONDS` | `30` | Integer 1–120; invalid values fail closed |
| `ATTACHMENT_SCAN_MAX_BYTES` | `26214400` | Positive and bounded; must not exceed platform ingress limit |
| `ATTACHMENT_MAX_COUNT` | `20` | Positive bounded count |
| `ATTACHMENT_MAX_TOTAL_BYTES` | `52428800` | Positive bounded aggregate size |
| `ATTACHMENT_SCAN_MAX_ATTEMPTS` | `3` | Integer 1–10 |
| `ATTACHMENT_SCANNER_ENDPOINT` | empty | Required only for `external`; never log credentials or URLs containing secrets |

Production configuration validation must reject unknown backends, missing required endpoint/credentials, invalid timeout/size/count, public scanner endpoints, and scanner configurations that are unavailable at startup. No attachment may become clean when validation or connectivity fails.

## Request and result contract

The scanner receives a private storage reference plus bounded operational metadata:

```text
storage_disk, storage_path, size_bytes, checksum_sha256, mime_type
```

It must not receive raw request logs or unrelated message payloads. The interface returns exactly one of:

```text
clean
infected
failed
```

`clean` requires a successful scanner verdict for the exact checksum/content. `infected` marks the attachment unsafe and blocks download. Timeout, unavailable backend, malformed scanner response, checksum mismatch, archive expansion limit, or MIME/content mismatch maps to `failed` and remains blocked.

Scanner signature/version may be retained as safe metadata. Malware signature names may be retained only if approved as non-sensitive operational data; raw scanner output, file bytes, credentials, and full provider responses are never logged.

## Quarantine lifecycle

1. Store bytes only on the private quarantine disk using a random/deterministic opaque key; never use the original filename as a path.
2. Persist state as `pending` with `is_safe=null`.
3. Queue a bounded scan attempt.
4. Mark `scanning` only while a lease is active.
5. `clean` ⇒ `is_safe=true` and controlled download may be considered.
6. `infected` ⇒ `is_safe=false`, retain quarantine evidence according to security policy, and block download.
7. `failed` ⇒ `is_safe=null`, retry within the attempt limit, then retain blocked terminal failure for operations review.

Already-clean content with the same attachment checksum must not be scanned again unless an authorized manual rescan is requested. Duplicate message/attachment records must not create a second unsafe verdict for the same content.

## Archive and MIME policy

Archive expansion is bounded by configured total bytes, file count, nesting depth, and execution time. Zip bombs, encrypted archives that cannot be inspected, malformed archives, MIME/content mismatch, and unsupported encodings fail closed. Filename extensions are never malware evidence and never override detected MIME/content.

No regex or filename-only malware detection is permitted.

## Retry and manual rescan

Transient scanner unavailable/timeout failures retry with bounded backoff `[60, 300, 900]` seconds, maximum three attempts by default. Terminal failure remains inaccessible and emits an operational alert. Manual rescan requires an active platform admin/security operator, an audit event, and the same scanner contract; it cannot force `clean`.

## Visibility and audit

Only `scan_status=clean` with `is_safe=true` and an existing private file may be downloaded. Pending, scanning, failed, infected, missing, or quarantined files fail closed. Download responses must never expose storage paths, scanner details, raw bytes in logs, or credentials. Processing logs contain only stage/status/duration and redacted error codes.

## Current limitation

No ClamAV daemon or approved external scanner is available in the current environment. Therefore no runtime scanner integration or attachment can be marked clean. Prompt 374 implementation must stop at pending/quarantine if the scanner dependency is unavailable.
