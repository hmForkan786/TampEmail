# Affiliate Privacy and Fraud

## Privacy principles

Affiliate tracking collects the **minimum necessary** data to attribute referrals
and prevent self-referral abuse.

### Stored safely

* HMAC-SHA256 hashes of IP and user-agent (`AFFILIATE_HASH_KEY` / `APP_KEY`)
* Opaque visitor token hash (raw token only in HttpOnly cookie)
* Bounded UTM parameters (alphanumeric + `_-.`, max 100)
* Sanitized landing/referrer URLs (max 2048, no `javascript:`)

### Never stored / never logged

* Raw IP or raw user-agent in affiliate tables
* Full payout details in audit logs
* Full referred-user email in affiliate APIs (masked: `j***@example.com`)
* Payment credentials
* Arbitrary fingerprint graphs

### Cookie / consent

Cookie name default: `temail_aff`. Contents: opaque random token only.
`HttpOnly`, `SameSite=Lax`, `Secure` in production. Expiration matches the
attribution window. Disclose affiliate cookies in the site privacy policy and
honor regional consent requirements before setting non-essential cookies.

### Retention

Unconverted expired/invalidated attributions are pruned after
`AFFILIATE_ATTRIBUTION_RETENTION_DAYS` (default 90):

```bash
php artisan affiliates:prune-attributions --dry-run
php artisan affiliates:prune-attributions --confirm
```

Converted attributions with financial conversions are retained.

## Fraud controls

`AffiliateFraudEvaluationService` is **deterministic**, not ML.

| Rule | Typical decision |
| --- | --- |
| Self-referral (user id) | reject |
| Self-referral (normalized email) | reject |
| Affiliate suspended | reject |
| Same IP hash affiliate↔buyer | manual_review |
| Fast click→purchase window | manual_review |
| Cookie tamper indicators | reject / fail-safe no attribution |
| Excessive click rates | manual_review / flag |

Uncertain cases → `manual_review`. Do not silently award commission.

## Self-referral prevention

* Affiliate user id ≠ buyer user id
* Normalized email inequality
* Own referral code cannot convert to self
* Admin impersonation must not silently attach attribution

## Referred-user display

Affiliate dashboards may show: masked email, conversion date, order category /
amounts, commission amount, status. No cross-affiliate visibility.
