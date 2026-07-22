# Changelog

All notable changes to this project are documented in this file.

## v0.4-architecture-cleanup

### Added
- `BaseRepositoryInterface` shared CRUD contract.
- `BaseEloquentRepository` shared Eloquent CRUD implementation.

### Changed
- Inbox, Email, Attachment, Domain and Subscription repositories now share a common base implementation.
- Removed duplicated CRUD logic (`create`, `update`, `delete`, `findById`) from the five module repositories without changing runtime behavior.
- Repository queries now build from `$this->model()->newQuery()` instead of static model calls (behavior identical).

### Fixed
- Domain model now supports `is_healthy` mass assignment and boolean casting.
- `InboxFiltersData` now parses `is_active` / `is_expired` null-safely.

### Notes
- No functional behavior changes (architecture cleanup only).
- BUG-02 (Update DTO null-clear) and BUG-04 (Subscription search) intentionally deferred.
- This milestone commit also brings the previously uncommitted vertical slices (Attachment, Domain, Email, Subscription Actions, DTOs and Services) under version control for the first time.
