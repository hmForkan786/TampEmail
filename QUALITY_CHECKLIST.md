# Quality Checklist

Every future feature must satisfy this checklist before commit.

## Scope

- The change maps to a project requirement, roadmap item, bug, or approved technical task.
- No unrelated files were modified.
- No business feature was added as a side effect.

## Architecture

- Module boundaries are respected.
- Controllers, commands, jobs, and Filament resources are thin.
- Business workflows live in explicit Actions or Services where appropriate.
- Cross-module communication uses public interfaces, events, jobs, or DTOs.

## Security And Privacy

- Authorization is explicit for every protected resource.
- Validation is explicit for every user, API, admin, or provider input.
- Temporary email content is treated as sensitive.
- Logs do not contain message bodies, full headers, secrets, credentials, or API tokens.
- Rendered HTML is sanitized.
- Public identifiers are non-enumerable.

## Data And Performance

- New persistence work has an approved data model.
- High-volume reads are bounded and index-aware.
- Cleanup paths use expiration fields and batching.
- Queue jobs are idempotent or duplicate-safe.
- Redis usage for cache, locks, throttling, and queues is deliberate.

## API And UX

- API endpoints are versioned.
- API responses follow the success and error envelope standards.
- Error messages are safe for public display.
- Rate limits are defined for public and API surfaces.

## Operations

- Failure behavior is documented.
- Observability requirements are defined.
- Long-running work is queued.
- Destructive actions are confirmed, authorized, and audited.
- Configuration is environment-driven where deployment behavior can vary.

## Tests And Tooling

- Unit, feature, integration, security, or architecture tests are added according to risk.
- `composer format` passes.
- `composer analyse` passes.
- `composer test` passes.
- `composer quality` passes before review.
