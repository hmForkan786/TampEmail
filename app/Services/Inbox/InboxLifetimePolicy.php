<?php
declare(strict_types=1);
namespace App\Services\Inbox;
final class InboxLifetimePolicy
{
    public function defaultHours(): int { return (int) config('inbox_lifetime.default_lifetime_hours', 0); }
    public function minimumHours(): int { return (int) config('inbox_lifetime.min_lifetime_hours', 0); }
    public function maxExtensionHours(): int { return (int) config('inbox_lifetime.max_extension_hours_per_request', 0); }
    public function maxAbsoluteHours(): int { return (int) config('inbox_lifetime.max_absolute_lifetime_hours', 0); }
    public function renewalEnabled(): bool { return config('inbox_lifetime.renewal_enabled', false) === true; }
    public function expirationEnabled(): bool { return config('inbox_lifetime.expiration_scheduler_enabled', false) === true; }
    public function expirationBatchSize(): int { return (int) config('inbox_lifetime.expiration_batch_size', 0); }
}
