# Outbound commercial entitlement enforcement

Outbound authorization is centralized in `OutboundAuthorizationService`.
`send_email` is required for every operation; `reply_email` and
`forward_email` are additionally required for their respective operations.
The commercial catalogue maps these implementation keys to the public
`outbound.send`, `outbound.reply`, and `outbound.forward` capabilities.

Future scheduling additionally requires `outbound.schedule`. A schedule
reserves usage at acceptance; dispatch rechecks both its normal operation and
the scheduling entitlement. A permanent pre-transport failure returns the
message to draft and releases the reservation; temporary deferrals retain it.
Provider retries reuse the same reservation, while a user-created resend is a
new message and reservation.

Custom sender-profile create, update, delete, default selection, explicit
selection, and send-time resolution require `outbound.sender_profiles`.

Usage is reserved atomically with message queue acceptance and is idempotent
per outbound message/idempotency key. The existing `OutboundUsageService`
locks usage rows and includes outstanding reservations, so the final quota
slot cannot be double accepted. Usage is committed on provider acceptance;
post-attempt transport failures remain consumed.

Entitlements and rollout are independent: both must permit delivery. Existing
ownership, sender readiness, recipient suppression, abuse/rate limits, and
attachment checks remain mandatory. Commercial denials use the safe
`feature_not_available` API code and audit `commercial.outbound_feature_denied`,
`commercial.sender_profile_denied`, or schedule lifecycle records; message
bodies and recipient contents are never audited.

Known pre-existing defect: `OutboundScheduleTest` retains one hard-coded 2025
DST test which is now historical. It is not changed by this prompt.
