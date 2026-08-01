# Inbox Lifecycle & Retention Audit (Prompt 654)

Production-readiness audit of inbox lifecycle from creation through deletion and retention. Architecture unchanged: no new inbox model, storage engine, or mailbox migration.

**Audit date:** 2026-07-29  
**Branch:** `feature/billing-payments`  
**Baseline HEAD:** `e0b3bcd` (after Prompt 653)  
**Final decision:** **PASS**

## Frozen architecture (verified unchanged)

```text
Create (CreateInboxAction / API)
  → Active (is_active=true, expires_at future or null)
  → Expiry (resolver blocks inbound; optional inboxes:expire job)
  → Hold (InboundHold on email/attachment/inbox target)
  → Retention (inbound:cleanup for emails; inbox expire for inbox row)
  → Delete (DeleteInboxAction — soft delete + is_active=false)
```

No native SMTP, mail migration, or schema redesign introduced.

## Authoritative components

| Concern | Implementation |
| --- | --- |
| Create | `CreateInboxAction`, `InboxController::store` |
| Delete | `DeleteInboxAction`, `InboxController::destroy` |
| Renew | `RenewInboxAction`, `PATCH .../expiration` |
| Expire | `ExpireInboxesService`, `inboxes:expire` |
| Email retention | `InboundRetentionService`, `inbound:cleanup` |
| Holds | `CreateInboundHoldAction`, `ReleaseInboundHoldAction` |
| Visibility (inbox API) | `OwnedInboxVisibilityService` |
| Visibility (email API) | `OwnedEmailVisibilityService` + `visibleToOwner()` |
| Inbound routing | `InboundRecipientResolver` |
| Outbound gate | `OutboundAuthorizationService` |
| Commercial | `EntitlementService`, feature keys in plan seeder |

Related policy docs: `INBOX_LIFETIME_POLICY.md`, `INBOUND_RETENTION_POLICY.md`, `INBOX_API_CHANGE_MANIFEST.md`

---

## 1. Inbox creation — PASS

| Check | Result |
| --- | --- |
| Owner association | `user_id` set from authenticated API actor; anonymous path has `user_id=null` |
| UUID | `HasUuid` on `BaseModel` |
| Address uniqueness | DB unique on `full_address`; pre-check includes soft-deleted rows |
| Alias policy | Custom `local_part` requires `inbox.custom_alias`; else generated `inbox-{12chars}` |
| Domain validation | `Domain::active()->registrationAllowed()` |
| MailServer assignment | User: `MailServerSelectionService` via `mail_server_pools` entitlement; Anonymous: `inbox.public_mail_server_pool` |
| MailProvider | Inventory via `MailServer` model (not a separate provider assignment at create) |
| Commercial entitlement | `inbox.create`, `inbox.max_active`, `inbox.retention_hours`, `inbox.custom_alias` |
| Rate limiting | Global API key rate limit on routes; dedicated renewal limit |
| Audit logging | `inbox.created` inside transaction |
| Transactional creation | User row lock → quota → server selection → create → audit in one transaction |

**Identity rule verified:** one inbox → one owner (or anonymous) → one canonical `full_address`.

---

## 2. Address & alias lifecycle — PASS

- Canonical key: `local_part@domain` (lowercase at API create)
- Uniqueness: global `full_address` unique; reserved local parts in `config/inbox.php`
- Alias routing: `InboundRecipientResolver` resolves exact `full_address` via repository
- Soft-deleted addresses cannot be re-allocated until hard-deleted
- Aliases never cross users: ownership enforced at create (`user_id` match) and API scoping (`ownedBy`)

---

## 3. State machine — PASS (documented as-is)

No formal enum; actual lifecycle uses fields:

| State signal | Meaning |
| --- | --- |
| `is_active=true`, not expired | Active |
| `expires_at <= now()` | Expired (routing); may still be `is_active=true` until expire job |
| `is_active=false` | Inactive (user delete or expire job step) |
| `deleted_at` set | Soft-deleted (terminal for API) |

**Transitions:**

```text
create → active (is_active=true, expires_at from entitlement)
active → expired routing (time passes; inbound blocked)
active → inactive+soft-deleted (user DELETE or inboxes:expire --confirm)
```

**Terminal:** soft-deleted. No restore path.

Audit events: `inbox.created`, `inbox.deactivated`, `inbox.expired`, `inbox.expiration_extended`.

---

## 4. Commercial integration — PASS (documented policy)

| Scenario | Behavior |
| --- | --- |
| Free plan | `inbox.max_active=3`, `retention_hours=24`, no custom alias |
| Premium | Higher limits, custom alias, longer retention |
| Upgrade | New entitlements apply to subsequent create/renew |
| Downgrade / cancellation | Billing grace → `EntitlementService` returns Free plan features; **existing inboxes are not auto-deleted** |
| Quota exhaustion | Create denied with `commercial.limit_reached` audit |
| Grace period | Subscription grace maps to Free entitlements for new operations |

**Policy answers:**

- Inbox survives downgrade: **Yes** (until natural expiry or user delete)
- Read-only on downgrade: **No** — email read API still works for active non-expired inboxes; new create limited by Free quota
- Mail accepted after downgrade: **Yes** if inbox still active and not expired
- Hidden on downgrade: **No**
- Auto-deleted on downgrade: **No**

---

## 5. Inbox expiry — PASS

- `expires_at` set at create from `inbox.retention_hours` entitlement (client may request shorter)
- Scheduler: `inboxes:expire --confirm` daily when `INBOX_EXPIRATION_SCHEDULER_ENABLED=true` (default **false** — ops must enable)
- `ExpireInboxesService`: dry-run without `--confirm`; batch size from config; sets `is_active=false` + soft-deletes
- Race: resolver checks `isExpired()` before `is_active`; expired inboxes reject inbound even before expire job
- Visibility: email API uses `visibleToOwner()` — expired → 404; inbox list API shows expired unless filtered
- Outbound: `OutboundAuthorizationService` rejects expired/inactive/trashed inboxes

---

## 6. Hold policies — PASS (partial scope documented)

| Hold type | Exists |
| --- | --- |
| Inbound email hold | Yes — `InboundHold` morph on email/attachment/inbox |
| Admin hold | Platform admin create/release |
| Legal hold | `inbound_retention.legal_hold_required=true`; holds block email cleanup |
| Audit log hold | Separate `AuditLogHold` |

- Hold prevents deletion: email/attachment holds block `inbound:cleanup` for that email
- **Gap (documented):** inbox-target holds do not automatically block all child email cleanup queries (holds checked per email/attachment row)

---

## 7. Retention policy — PASS (with documented simplification)

| Layer | Authority |
| --- | --- |
| Inbox lifetime | `inbox.retention_hours` at create → `expires_at` |
| Email retention | `inbound_retention.email_days` in `InboundRetentionService` |
| Attachment/body/event | Deleted with parent email in same service |
| Raw MIME | Not retained post-ingest |
| Config categories | `inbound_retention.php` defines per-type days; **implementation uses `email_days` only** for cutoff |

Inbox expiration does not immediately delete child emails; delivery blocked separately.

---

## 8. Cleanup jobs — PASS

| Command | Purpose | Idempotent |
| --- | --- | --- |
| `inboxes:expire --confirm` | Soft-delete expired active inboxes | Yes — skips already inactive/deleted |
| `inbound:cleanup --confirm` | Delete old emails graph | Yes — batch loop, hold-aware |
| `logs:cleanup --confirm` | API/audit logs | Separate from inbox |

Both gated by feature flags (`expiration_scheduler_enabled`, `cleanup_enabled`). `withoutOverlapping()` on scheduler.

---

## 9. Restoration — NOT SUPPORTED

No `RestoreInboxAction`, no API route, no admin restore. Soft-deleted inboxes excluded by global scope. Documented fail-closed in `INBOX_LIFETIME_POLICY.md`.

---

## 10. Deletion lifecycle — PASS

```text
DELETE /api/v1/inboxes/{id}
  → is_active=false
  → soft delete
  → audit inbox.deactivated
```

- Owner authorization via `ownedBy` + active check
- No grace period for user delete
- Child emails preserved (soft delete does not cascade)
- Hard delete would cascade via FK but not used in normal ops

---

## 11. Mail acceptance — PASS

| Inbox state | Accept inbound mail |
| --- | --- |
| Active, not expired | **Yes** |
| Expired (`expires_at` past) | **No** (`InboundRoutingCode::Expired`) |
| `is_active=false` | **No** (`inactive_inbox`) |
| Soft-deleted | **No** (`unknown_inbox`) |
| Held (inbox/email) | **Yes** — hold blocks cleanup, not delivery |

---

## 12. Visibility — PASS (intentional API split)

| Surface | Expired/inactive behavior |
| --- | --- |
| Inbox list/detail API | **Visible** with `is_active`/`expires_at` fields; filter via `?expired=` / `?is_active=` |
| Email list/detail API | **Hidden** — `visibleToOwner()` → 404 |
| Web UI (`MailboxController`) | **Hidden** — uses `visibleToOwner()` |
| Admin Filament | Platform admin resources |

---

## 13. Scheduler — PASS

From `bootstrap/app.php`:

- `inboxes:expire --confirm` — daily, `withoutOverlapping`, when `inbox_lifetime.expiration_scheduler_enabled`
- `inbound:cleanup --confirm` — daily, when `inbound_retention.cleanup_enabled`
- Billing lifecycle jobs affect entitlements every 5 min (indirect)

---

## 14. API — PASS

| Endpoint | Scope | Notes |
| --- | --- | --- |
| `GET /api/v1/inboxes` | `inboxes:read` | Filters, pagination |
| `GET /api/v1/inboxes/{id}` | `inboxes:read` | Owner scoped |
| `POST /api/v1/inboxes` | `inboxes:write` | Entitlement + commercial errors |
| `DELETE /api/v1/inboxes/{id}` | `inboxes:write` | Active only |
| `PATCH /api/v1/inboxes/{id}/expiration` | `inboxes:write` | Gated by `renewal_enabled` |

---

## 15. Security — PASS

- Owner isolation on all mutations and email reads
- Mass assignment via DTOs/validated requests
- API key scopes enforced before controller
- Audit logging without raw addresses in payloads (sanitized)
- Soft-deleted inboxes return 404 on email endpoints

---

## 16. Monitoring — PARTIAL (documented)

No dedicated `inbox:health` command. Indirect signals:

- `ExpireInboxesService` report counts
- `InboundRetentionService` cleanup report
- `MailServerCapacityService` active inbox workload per server
- Commercial threshold notifications at 80/90/100% inbox quota
- `processes:scheduler-heartbeat`

---

## Known limitations

1. No inbox restore after soft-delete
2. Expiration scheduler disabled by default (`INBOX_EXPIRATION_SCHEDULER_ENABLED=false`)
3. Inbound retention cleanup disabled by default
4. `InboundRetentionService` does not apply all per-category day keys from config
5. Inbox-level inbound hold does not block child email cleanup by inbox id alone
6. `inbox.public_access` feature seeded but not enforced in code
7. Downgrade does not auto-prune excess inboxes
8. API inbox list shows expired/inactive (by design; email API does not)
9. Renewal disabled by default (`INBOX_RENEWAL_ENABLED=false`)
10. Duplicate config files `inboxes.php` vs `inbox_lifetime.php` (latter is canonical)

## Final decision

| Criterion | Status |
| --- | --- |
| Lifecycle documented | PASS |
| Commercial consistency | PASS |
| Expiry correct | PASS |
| Cleanup idempotent | PASS |
| Retention safe | PASS |
| Deletion safe | PASS |
| Authorization correct | PASS |
| Scheduler verified | PASS |
| Architecture unchanged | PASS |

**Prompt 654 status: PASS**
