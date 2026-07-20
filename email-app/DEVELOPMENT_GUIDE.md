# Development Guide

## Local Setup

1. Install PHP, Composer, Node.js, MySQL, and Redis versions compatible with Laravel 12.
2. Run `composer install`.
3. Run `npm install`.
4. Copy `.env.example` to `.env` and configure local database, Redis, mail, cache, queue, and storage values.
5. Run `php artisan key:generate`.
6. Run `php artisan migrate` only after approved migrations exist.
7. Use `composer dev` for the local server, queue listener, logs, and Vite during active development.

## Coding Workflow

1. Read `PROJECT_SPECIFICATION.md`, `PROJECT_ARCHITECTURE.md`, `MODULE_BREAKDOWN.md`, `DATABASE_ER_DESIGN.md`, `CODING_STANDARDS.md`, and `FEATURE_ROADMAP.md` before implementing feature work.
2. Keep framework entry points thin.
3. Put business behavior in the appropriate module boundary.
4. Do not add migrations, models, controllers, services, jobs, or Filament resources without an explicit task and approved design.
5. Keep public, authenticated, API, admin, ingestion, parsing, retention, abuse, billing, and analytics concerns separate.

## Branch Strategy

Use short-lived branches from the default branch:

- `feature/<scope>` for product behavior.
- `fix/<scope>` for defects.
- `chore/<scope>` for tooling, documentation, and maintenance.
- `docs/<scope>` for documentation-only work.
- `refactor/<scope>` for behavior-preserving internal changes.

Rebase or update from the default branch before requesting review.

## Commit Naming Convention

Use Conventional Commits:

- `feat: add inbox creation policy boundary`
- `fix: prevent expired inbox message access`
- `chore: configure static analysis`
- `docs: document retention review checklist`
- `test: cover API rate limit response`
- `refactor: extract alias normalization value object`

Commits must be focused and must not mix unrelated changes.

## Pull Request Checklist

- Scope is linked to a documented requirement or roadmap phase.
- No unrelated files were changed.
- No secrets, raw message bodies, tokens, or private headers were committed.
- Public/API/admin behavior includes authorization rules.
- User input has validation boundaries.
- Queue work is idempotent or duplicate-safe.
- Retention, abuse, observability, and failure behavior are documented where relevant.
- Tests cover success, failure, authorization, and edge cases appropriate to the change.
- `composer quality` passes locally.

## Code Review Checklist

- The change respects module boundaries.
- Controllers, commands, jobs, and Filament resources remain orchestration-focused.
- Business logic is not placed in Blade views, config files, or framework bootstrapping.
- Queries are bounded and indexed for high-volume paths.
- Sensitive data is minimized and redacted from logs.
- External services have configuration, timeout, retry, and failure strategy.
- Destructive operations are confirmed, authorized, audited, and recoverable where appropriate.

## Static Analysis Workflow

Run:

```bash
composer analyse
```

PHPStan uses Larastan at level 6. Raise the level only when the existing codebase is clean at the current level and the team can keep it clean.

## Formatting Workflow

Run:

```bash
composer format
```

Laravel Pint uses the Laravel preset. Formatting changes should be committed with the feature they belong to, or in a dedicated `chore` commit when normalizing existing files.

## Future GitHub Actions Structure

When CI is added, create workflow files under `.github/workflows/` with separate jobs for:

- Composer dependency installation and cache.
- Laravel Pint format check.
- Larastan/PHPStan static analysis.
- Pest/PHPUnit test suite.
- Frontend dependency installation and build.

Do not add workflow files until repository secrets, PHP version, database service, Redis service, and deployment policy are agreed.
