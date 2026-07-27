# Commercial Plan Feature Catalogue

Prompt 627 provisions two managed plans using stable `plans.slug` values:
`free` (the future resolver fallback) and `premium`. Both are active and
public through plan metadata. Display names are not identifiers.

Run the catalogue explicitly:

```bash
php artisan db:seed --class=CommercialPlanFeatureSeeder
```

The seeder is idempotent. It upserts only managed plans/features by stable key
and updates only their managed pivots with `syncWithoutDetaching`; it never
deletes custom plans, features, subscriptions, or unrelated mappings. It is
not registered in `DatabaseSeeder` because applying commercial plan values to
an existing production database must remain an explicit operator action.

Feature keys use the existing implementation keys where they already exist:
`inbox.max_active` -> `max_inboxes`, `api.keys.max_active` -> `max_api_keys`,
`outbound.send` -> `send_email`, `outbound.reply` -> `reply_email`,
`outbound.forward` -> `forward_email`, and
`outbound.max_messages_per_period` -> `outbound_messages_per_period`.

The complete catalogue is: `inbox.create`, `max_inboxes`,
`inbox.custom_alias`, `inbox.public_access`, `inbox.retention_hours`,
`message.max_received`, `attachment.download`, `attachment.max_size_mb`,
`attachment.max_per_message`, `send_email`, `reply_email`, `forward_email`,
`outbound.schedule`, `outbound.sender_profiles`,
`outbound_messages_per_period`, `outbound_recipients_per_period`,
`outbound_attachment_bytes_per_period`, `outbound_retention_days`, `api.read`,
`api.write`, `api.max_requests_per_minute`, `max_api_keys`, `webhook.access`,
`webhook.max_endpoints`, `ads.visible`, `analytics.basic`,
`analytics.advanced`, `priority.processing`, `support.priority`, and
`mail_server_pools`.

Boolean values are stored as `{ "enabled": bool }`; numeric limits as
`{ "limit": integer }`; usage limits additionally include
`reset_period: monthly`. `0` is an explicit denial, not unlimited. Every
numeric commercial mapping is present and finite. Missing mappings are never
seeded as unlimited. Existing `null` unlimited compatibility is unchanged
until Prompt 628 hardens resolution.

| Capability | Free | Premium |
| --- | ---: | ---: |
| Active inboxes / retention hours / received messages | 3 / 24 / 100 | 25 / 720 / 5000 |
| Attachment MB / attachments per message | 5 / 3 | 20 / 10 |
| Send, reply, forward, schedule, sender profiles | disabled | enabled |
| Outbound messages / recipients / bytes per month | 0 / 0 / 0 | 1000 / 2500 / 104857600 |
| API read / write / rpm / active keys | yes / no / 20 / 1 | yes / yes / 120 / 10 |
| Webhooks / endpoints | no / 0 | yes / 10 |
| Ads / basic analytics / advanced analytics | visible / no / no | hidden / yes / no |
| Priority processing / support | no / no | yes / yes |

Premium's finite 1000-message allowance is a new conservative catalogue
value: no prior premium usage allowance existed in configuration. It avoids
inventing an implicit unlimited tier. `outbound_retention_days` is 1/30 days,
matching the shipped retention configuration. Resolver fallback, active-plan
validation, expiry validation, and missing-entitlement denial are deliberately
deferred to Prompt 628.
