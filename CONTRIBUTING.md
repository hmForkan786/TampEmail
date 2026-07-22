# Contributing

## Coding Standards

Follow Laravel 12 conventions, PSR-12, and `CODING_STANDARDS.md`.

- Use clear business language.
- Keep controllers, commands, jobs, and Filament resources thin.
- Prefer Actions, Services, DTOs, Value Objects, Events, Listeners, Jobs, and Policies where justified.
- Do not log sensitive email content, full headers, credentials, or API tokens.
- Do not render unsanitized email HTML.

## Folder Rules

Respect the architecture in `PROJECT_ARCHITECTURE.md`.

- Business capabilities belong under the appropriate future `App/Features/<Feature>` module.
- Shared infrastructure belongs in `App/Infrastructure` or `App/Support`.
- Filament admin code must remain separate from public user interfaces.
- Tests should be placed near the behavior category they verify.
- Vendor files, generated assets, storage files, and environment files must not be edited manually.

## Naming Conventions

- Classes: `PascalCase`.
- Methods and variables: `camelCase`.
- Database tables and columns: `snake_case`.
- Environment variables: uppercase `SNAKE_CASE`.
- Queue names: lowercase `kebab-case`.
- API JSON fields and error codes: `snake_case`.
- Events describe past-tense facts.
- Actions use verb phrases.

## Pull Request Rules

- Keep each pull request focused on one intent.
- Do not mix feature work with unrelated formatting or dependency churn.
- Include tests for behavior changes.
- Run `composer quality` before requesting review.
- Document any deferred risk, missing test coverage, or operational follow-up.
- Never include secrets or private runtime data.

## AI Development Rules

- Read and follow the project specification, architecture, module breakdown, database design, coding standards, and roadmap before modifying files.
- Modify only files required by the requested task.
- Do not create business features unless explicitly requested.
- Do not create migrations, models, controllers, services, jobs, or Filament resources unless explicitly requested and backed by approved design.
- Do not remove existing configuration unless the task explicitly requires it.
- Stop after completing the requested task.

## Review Process

1. Author verifies scope, formatting, static analysis, and tests.
2. Reviewer checks architecture, security, privacy, performance, and maintainability.
3. Author resolves findings with focused commits.
4. Reviewer approves only after quality checks pass and risks are documented.
