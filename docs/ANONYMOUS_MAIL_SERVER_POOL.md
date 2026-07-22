# Anonymous Mail Server Pool Policy

Status: configuration contract established by Prompt 305. Runtime anonymous provisioning is implemented in Prompt 306.

## Purpose

Anonymous/public inbox provisioning must select mail servers through an explicit, configuration-driven pool policy. The current `mail_servers` schema has no `is_public` flag, so arbitrary servers or servers with `pool_key = null` must never be used for anonymous flows.

## Configuration

| Item | Value |
|---|---|
| Environment variable | `PUBLIC_MAIL_SERVER_POOL` |
| Config key | `inbox.public_mail_server_pool` |
| Config file | `config/inbox.php` |

### Allowed value format

- A single non-empty string matching an existing `mail_servers.pool_key` value.
- Leading and trailing whitespace is trimmed during config load.
- Whitespace-only values are treated as empty (anonymous provisioning disabled).

### Empty value behaviour

When `PUBLIC_MAIL_SERVER_POOL` is unset, empty, or whitespace-only:

- `config('inbox.public_mail_server_pool')` resolves to `null`.
- Anonymous mail-server provisioning is **disabled**.
- No fallback to `pool_key = null` servers, arbitrary servers, or entitlement pools.

### Production requirement

Production deployments **must** set `PUBLIC_MAIL_SERVER_POOL` explicitly to a dedicated pool key before enabling anonymous inbox provisioning. The configured pool must contain only servers intended for public traffic.

Do not commit production pool key values to source control. Configure the value through deployment secrets or environment management.

### Non-production default behaviour

Local and staging environments default to `null` (disabled) when the variable is absent or empty. Developers opt in by setting a test pool key and provisioning matching `mail_servers` records.

### Security implications

- This configuration is the **sole authority** for public mail-server exposure.
- Anonymous flows must perform an **exact** `pool_key` match against the configured value.
- Servers with `pool_key = null` are never eligible for anonymous provisioning.
- Authenticated user provisioning continues to use entitlement-driven pool resolution (`mail_server_pools` feature) and is unaffected by this setting.

### Runtime config caching

Production deployments must run `php artisan config:cache` after changing `PUBLIC_MAIL_SERVER_POOL`. Anonymous provisioning code must read `config('inbox.public_mail_server_pool')` at runtime and must not cache the value in application code outside Laravel's config layer.

## Anonymous eligibility policy

A mail server is eligible for anonymous provisioning only when **all** conditions hold:

1. `config('inbox.public_mail_server_pool')` is a non-null string.
2. The server's `pool_key` **exactly equals** that configured value.
3. The server is active (`is_active = true`).
4. The server is healthy/usable (recent `last_health_check_at` within the platform health window).
5. The server is not soft-deleted.
6. The server has available capacity (`max_inboxes` is null or current utilization is below the limit).

Servers with `pool_key = null` never satisfy condition 2 and are therefore never eligible.

## Authenticated entitlement flow

Authenticated inbox provisioning is unchanged:

- Pool resolution remains in `MailServerSelectionService`.
- Allowed pools come from the user's `mail_server_pools` entitlement feature value.
- This configuration does not override, merge with, or fall back to entitlement pools.

## Prompt 306 implementation contract

Prompt 306 will wire anonymous provisioning to this configuration without changing authenticated flows:

1. Read `config('inbox.public_mail_server_pool')` when assigning a mail server for anonymous/public inbox creation.
2. When the config value is `null`, fail closed: anonymous provisioning is disabled and must not select any mail server.
3. When the config value is set, pass a single-element pool key array containing only that exact trimmed string to the existing repository selection method (`selectAvailableForPoolsForUpdate`).
4. Do not modify `MailServerSelectionService`, authenticated entitlement resolution, routes, controllers, or the `mail_servers` schema.
5. Do not hardcode default pool keys or infer pools from domain, hostname, or nullable `pool_key` values.
6. Surface a safe, documented failure when no eligible server exists in the configured pool (for example, reuse the existing eligible-mail-server-unavailable exception pattern).
7. Add feature tests covering enabled/disabled config states, exact pool matching, and rejection of `pool_key = null` servers.
