# Analytics Architecture

Prompt 663 establishes a **provider-neutral, event-driven Analytics & Business
Intelligence Platform**. Analytics is a **read model + aggregation layer** only.

## Frozen pipeline

```text
Application Events / Source Tables
        │
        ▼
Analytics Event Collector
        │
        ▼
Aggregation Services (scheduled)
        │
        ▼
Analytics Dashboard / Reports / Charts / CSV
```

**Forbidden:**

* Changing Billing payment or entitlement logic
* Changing Mail ingestion / outbound processing
* Changing API response contracts
* Executing business logic inside Analytics
* Storing PII (email, subject, body, raw IP, user agent, tokens)

## Principles

1. **Provider-neutral** — no Stripe/AdSense/affiliate-provider branching in the
   analytics layer; metrics come from normalized domain tables and events.
2. **Event-driven where possible** — Ads `AdRendered` / `AdClicked` and
   `SubscriptionLifecycleEvent` feed `analytics_events` via fail-open listeners.
3. **Scheduled aggregation** — no real-time OLAP engine; `analytics:rollup`
   builds `analytics_daily_rollups` from existing subsystem tables.
4. **Fail-open** — collector/listener failures log warnings and never break Ads,
   Billing, or Mail.
5. **Owner isolation** — rollups use `scope_key` (`platform` or owner UUID);
   admin dashboard reads platform scope only. Dimensions are UUID/status only.
6. **No business logic duplication** — Analytics counts and sums existing
   ledgers; it does not recalculate commissions, entitlements, or delivery.

## Domain models

| Model | Table | Purpose |
| --- | --- | --- |
| `AnalyticsEvent` | `analytics_events` | Append-only sanitized event stream |
| `AnalyticsDailyRollup` | `analytics_daily_rollups` | Daily metric buckets |
| `AnalyticsAggregationRun` | `analytics_aggregation_runs` | Health / backlog / failure tracking |

## Metric catalogue

| Domain | Metrics |
| --- | --- |
| Users | `registrations`, `active_users`, `premium_users`, `free_users`, `retention_bps` |
| Inbox | `inboxes_created`, `active_inboxes`, `inboxes_expired`, `inboxes_renewed` |
| Email | `emails_received`, `emails_sent`, `emails_reply`, `emails_forward`, `attachments_used` |
| Billing | `revenue_minor`, `orders`, `orders_paid`, `orders_failed`, `mrr_minor`, `arr_minor` |
| Affiliate | `affiliate_clicks`, `affiliate_signups`, `affiliate_conversions`, `commission_minor`, `affiliate_withdrawals` |
| Ads | `ad_impressions`, `ad_clicks`, `ad_ctr_bps`, `ad_revenue_minor` |
| API | `api_requests`, `api_errors`, `api_rate_limited`, `api_key_usage` |

## Services

| Service | Responsibility |
| --- | --- |
| `AnalyticsEventCollector` | Sanitized ingest + PII deny-list |
| `AnalyticsAggregationService` | Scheduled day rollup + backfill |
| `AnalyticsDashboardService` | Admin dashboard summary |
| `AnalyticsReportService` | Daily/weekly/monthly/custom + CSV |
| `AnalyticsTrendService` | Chart series |
| `AnalyticsHealthCheckService` | Healthy / backlog / failures |
| `AnalyticsPruneService` | Retention cleanup |
| `AnalyticsMetricCatalog` | Canonical metric list |

## Event sources (reuse, do not modify)

Commercial · Billing · Affiliate · Ads · Inbox · Inbound Mail · Outbound Mail ·
Attachments · API · Webhooks (via existing tables / audit actions)

Listeners only **observe** Ads + subscription lifecycle events; source modules
are unchanged.

## Filament (admin)

Navigation group **Analytics**:

* Dashboard — KPI cards, per-domain metrics, trend charts
* Reports — period filters + CSV export
* Control — health JSON, safe settings, manual backfill

Access: platform admin only (`isPlatformAdmin()`).

## Commands

| Signature | Role |
| --- | --- |
| `analytics:rollup {--date=} {--backfill}` | Aggregate day / backfill window |
| `analytics:health` | JSON health (exit 0/1) |
| `analytics:prune --confirm` | Retention delete |
| `analytics:export` | CSV to local storage |

## Config

`config/analytics.php` — `ANALYTICS_*` env keys. Queue name default: `analytics`.

## Security

* PII deny-list on dimension keys
* No email bodies / subjects in rollups
* Admin-only Filament pages
* Aggregation is read-only against source tables
