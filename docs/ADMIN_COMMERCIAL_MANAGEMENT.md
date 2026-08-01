# Admin commercial management

The Commercial navigation group is visible only to active platform admins;
the Plan and Subscription policies enforce this on the server, independently
of navigation visibility. Regular users, operators, guests, and forged direct
resource access are denied.

Plans show stable slug, active state, prices, feature count and subscription
count. `free` and `premium` retain their stable slug and cannot be deleted.
Free must stay active and priced at zero. Deactivating Premium is allowed only
through the protected plan service; affected users resolve to Free on their
next entitlement check.

The central plan management service validates mapped Boolean values as actual
booleans and numeric limits as finite integers from 0 through 1,000,000.
`0` means denied. The Free plan cannot enable outbound, API write, or webhook
access; it must retain inbox creation, ads visibility, and at least one inbox.
All updates use a locked `updated_at` comparison, transaction, atomic pivot
update, and audit record. There is no entitlement cache to invalidate.

The Plan edit header includes a mapped-feature editor that resolves the
selected feature from the plan and delegates every mutation to the management
service; it does not write the pivot directly.

Subscription records are read-only except confirmed lifecycle actions, which
call `SubscriptionLifecycleService`; raw status fields are never offered.
Payment checkout, gateway operations, invoices, end-user billing UI, bulk
Premium grants, and usage resets are outside this interface.

Known pre-existing regression: `OutboundScheduleTest` hard-codes a 2025 DST
date, now in the past relative to July 2026. It is unchanged by this prompt.
