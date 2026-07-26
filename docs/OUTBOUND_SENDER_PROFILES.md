# Outbound Sender Profiles

Status: implemented (Prompt 623).

## Overview

Sender profiles bind **exactly one inbox** and provide optional:

- **Display name** (`from_display_name` on send — never overrides `from_address`)
- **Reply-To** (must be an owned, active, non-expired inbox address)
- **Signatures** (text + HTML, sanitized on write)

Profiles are scoped per `(user_id, inbox_id)`. Multiple profiles per inbox are allowed; names are unique among non-deleted rows (enforced transactionally).

## Selection order (drafts)

1. Explicit `sender_profile_id` on create/update
2. Inbox default profile (`is_default = true` for that inbox)
3. None (identity fields cleared unless explicitly set)

Changing inbox clears an incompatible profile and re-resolves the default for the new inbox.

## Signatures

Applied on draft save when a profile is selected:

- Text: wrapped in `[[outbound-sig-start]]` … `[[outbound-sig-end]]`
- HTML: wrapped in `<!--outbound-sig-start-->` … `<!--outbound-sig-end-->`

If markers already exist, the marked region is replaced; otherwise the signature is appended once.

Markers are **stripped** in `prepareSendableContent` / `DeliverOutboundMessageJob` before transport — inner signature content is kept.

Include flags per operation: `include_on_send`, `include_on_reply`, `include_on_forward`.

## Scheduled / queued immutability

- **Schedule:** identity fields (`from_display_name`, `reply_to_*`) are snapshotted onto the message row with the body at schedule time.
- **Queued+:** transport reads message row only; profile edits/deletes do not mutate queued or sent rows.
- **Dispatch:** revalidates inbox ownership and reply-to; uses snapshotted fields for scheduled messages.

## Deletion

Profiles use soft deletes. Deleting a profile clears `sender_profile_id` on **draft** messages only. Scheduled snapshots and queued identity remain unchanged.

## API

| Method | Path | Description |
|--------|------|-------------|
| GET | `/api/v1/outbound-sender-profiles` | List (`?inbox_id=` optional) |
| GET | `/api/v1/outbound-sender-profiles/{id}` | Show |
| POST | `/api/v1/outbound-sender-profiles` | Create |
| PATCH | `/api/v1/outbound-sender-profiles/{id}` | Update (requires `version`) |
| DELETE | `/api/v1/outbound-sender-profiles/{id}` | Soft delete |
| POST | `/api/v1/outbound-sender-profiles/{id}/default` | Set default |
| POST | `/api/v1/outbound-sender-profiles/{id}/make-default` | Set default (alias) |

Drafts accept optional `sender_profile_id` and `from_display_name`. Direct send accepts optional `sender_profile_id`.

Scopes: `outbound_messages:read` / `outbound_messages:write`.

## Error codes

`profile_not_found`, `profile_conflict`, `profile_inactive`, `profile_inbox_mismatch`, `reply_to_forbidden`, `signature_too_large`, `display_name_invalid`, `profile_limit_exceeded`, `feature_disabled`.

## Config (`config/outbound.php`)

```php
'sender_profiles' => [
    'enabled' => env('OUTBOUND_SENDER_PROFILES_ENABLED', true),
    'max_name_length' => 100,
    'max_signature_text_bytes' => 10000,
    'max_signature_html_bytes' => 20000,
    'max_per_inbox' => 20,
],
```

## Audit (safe metadata only)

- `outbound.sender_profile_created`
- `outbound.sender_profile_updated`
- `outbound.sender_profile_deleted`
- `outbound.sender_profile_defaulted`
- `outbound.sender_profile_applied` — inbox_id and profile_id only
- `outbound.sender_profile_rejected` — result_code only

Never logs display names, reply-to addresses, or signature content.

## Web UI

| Method | Path | Description |
|--------|------|-------------|
| GET | `/outbound-sender-profiles` | List profiles and create form |
| POST | `/outbound-sender-profiles` | Create |
| GET | `/outbound-sender-profiles/{id}/edit` | Edit form |
| PATCH | `/outbound-sender-profiles/{id}` | Update (requires `version`) |
| DELETE | `/outbound-sender-profiles/{id}` | Soft delete |
| POST | `/outbound-sender-profiles/{id}/default` | Set default |

Requires authenticated web session (same as drafts).

## Retention

On content redaction, message rows clear `from_display_name`, `reply_to_address`, `reply_to_name`, and `sender_profile_id`. Soft-deleted profiles have identity and signature fields nulled at delete time. Profile signature fields are also nulled for deleted users via `OutboundSenderProfileService::redactProfilesForDeletedUser()`.
