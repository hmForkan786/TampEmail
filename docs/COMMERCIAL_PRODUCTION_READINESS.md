# Commercial Production Readiness

Audit date: 2026-07-28. Scope: commercial work delivered in Prompts 626–634, verified by Prompt 635.

## Verified

| Area | Result | Evidence |
| --- | --- | --- |
| Plans | Verified | Canonical Free/Premium catalogue via `CommercialPlanFeatureSeeder`; admin mutations via `CommercialPlanManagementService`. |
| Features | Verified | Typed feature catalogue keys; boolean/integer/json value contracts; Free invariants enforced at management layer. |
| Entitlements | Verified | `EntitlementService` is the effective-plan resolver (fail-closed). Unused `User::isPremium()` helper removed. |
| Lifecycle | Verified | `SubscriptionLifecycleService` uses transactions + `lockForUpdate`, rejects overlapping Active/Trial grants, audits transitions; Free is fallback, not a subscription row. |
| Inbox | Verified | Create enforces `inbox.create`, `inbox.max_active`, alias entitlement; denials map through `CommercialResponseFactory`; threshold audits on inventory saturation. |
| Outbound | Verified | Dispatch-time `assertCanSend` recheck, usage reservation/commit/release, sender ownership, suppression, rollout/emergency stop. |
| API | Verified | Scope allowlist + live capability recheck, entitlement middleware, rate limit from `api.max_requests_per_minute`, rotation/revoke ownership paths, unified commercial envelopes. |
| Webhooks | Verified | HTTPS + SSRF validator, encrypted secrets, HMAC signatures, delivery retries, entitlement recheck before dispatch, `afterCommit` scheduling. |
| Usage | Verified | `CommercialQuotaResolver` is the single finite-quota calculator; `CommercialUsageSummaryService` + API usage endpoint consume it. |
| Notifications | Verified | `CommercialThresholdNotificationService` emits one audit per threshold crossing (80/90/100) with cache idempotency. |
| Dashboard | Verified | Mailbox web surface receives commercial usage summary from the shared usage service. |

## Release gates cleared in Prompt 635

1. Architecture single-source checks for entitlement, quota, response envelopes, and subscription lifecycle.
2. Regression filters: Commercial, Entitlement, Usage, Subscription, Inbox, Outbound, Webhook, Api — all exit 0 after hardening.
3. Static analysis on changed app paths (`ProcessInboundMessageJob`, `User`) after Pint.
4. `git diff --check` clean for Prompt 635 changes.

## Known limitations

Only observed limitations:

- Relational concurrency scenarios for inbox quota, API-key quota, and webhook endpoints are intentionally skipped on SQLite; MySQL/PostgreSQL CI remains required for lock-race proof (`docs/RELATIONAL_TEST_MATRIX.md`).
- `subscription_usage` uniqueness for `(subscription_id, feature_id, period)` is enforced by application `lockForUpdate` paths, not a unique DB constraint.
- `DeliverWebhookJob` relies on delivery-row uniqueness rather than `ShouldBeUnique` job locking.
- `OutboundScheduleTest` still hard-codes a historical 2025 DST fixture date (`docs/ADMIN_COMMERCIAL_MANAGEMENT.md`).
- SQLite feature tests are not production concurrency proof.

No payment, billing, pricing, gateway, affiliate, ads, template-builder, or new commercial capability was added by this audit.
