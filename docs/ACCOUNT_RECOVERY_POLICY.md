# Account Recovery Policy

## Scope

Prompt 664 supports **admin-assisted recovery** only. There is no automated identity document (KYC) verification.

## When to use

- User lost access to the registered email inbox
- User suspects account compromise and cannot complete normal reset
- Controlled email change after admin review

## Flow

```text
User submits recovery request
→ immutable request record (hashed claimed email, encrypted optional new email/notes)
→ admin start review
→ approve / reject
→ complete (only if approved)
→ stage pending email if requested
→ signed verification to new email
→ atomic email replace + notify old email
→ sessions revoked; API keys revoked per config
→ password reset required
```

## Statuses

`submitted` → `under_review` → `approved` | `rejected` → `completed` | `cancelled`

## Dual approval

When `IDENTITY_RECOVERY_DUAL_APPROVAL=true` and a new email is requested, two distinct platform admins must approve before completion.

## Rules

- Generic public submission response (no account existence disclosure)
- Rate limited
- Reviewer cannot mark completed without approval
- New email is never authoritative until verified
- Email uniqueness enforced under row locks
- Evidence notes encrypted; masked in admin UI
- Review history is append-only
- Expiry via `IDENTITY_RECOVERY_EXPIRE_HOURS` (default 72)

## What recovery does not do

- Does not automatically reactivate banned/suspended accounts without separate admin status change
- Does not collect government ID documents
- Does not bypass email verification of the new address
