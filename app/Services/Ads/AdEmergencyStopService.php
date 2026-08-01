<?php

declare(strict_types=1);

namespace App\Services\Ads;

use App\Models\User;
use App\Services\Audit\AuditLogWriter;
use Illuminate\Support\Facades\Cache;

final class AdEmergencyStopService
{
    public function __construct(private readonly AuditLogWriter $audit) {}

    public function isStopped(): bool
    {
        return (bool) Cache::get($this->cacheKey(), false);
    }

    public function engage(?User $actor = null): void
    {
        Cache::forever($this->cacheKey(), true);
        $this->audit->write(
            action: 'ads.emergency_stop.engaged',
            actorUserId: $actor?->getKey(),
            newValues: ['emergency_stop' => true],
        );
    }

    public function release(?User $actor = null): void
    {
        Cache::forget($this->cacheKey());
        $this->audit->write(
            action: 'ads.emergency_stop.released',
            actorUserId: $actor?->getKey(),
            newValues: ['emergency_stop' => false],
        );
    }

    private function cacheKey(): string
    {
        return (string) config('ads.emergency_stop_cache_key', 'ads:emergency_stop');
    }
}
