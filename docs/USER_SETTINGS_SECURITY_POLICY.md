# User Settings Security Policy

## Authorization

Every settings route requires:

- `auth`
- `web.active` (active/pending only; suspended/banned/closed fail closed)

Owner-only. Admin must not use these routes to mutate another user. Admin visibility is a separate Filament read-only overview.

## Password confirmation

Required for:

- Session revoke / revoke others
- API key create / rotate / revoke (configurable)
- Privacy export request
- Account closure
- Affiliate payout detail updates

## Email change

Ordinary profile update **cannot** change email. Staged Identity flow only:

1. Request new email → `pending_email`
2. Signed verification
3. Atomic replacement
4. Session revocation

## Secrets

- API key plaintext shown exactly once (session flash)
- Never embed secrets in later page HTML
- Export archives exclude passwords, API secrets, recovery evidence, raw session IDs
- Payout details stored encrypted; UI shows masked values only

## Notifications policy

- Critical `security` category cannot be fully disabled
- Transactional billing email remains enforced
- Marketing consent is separate from terms and transactional notifications
- Unknown notification categories fail closed

## Cache / rendering

Settings pages use Blade escaping and should be treated as `Cache-Control: private, no-store` sensitive surfaces.
