<?php

use App\Models\AuditLog;
use App\Services\Commercial\CommercialThresholdNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

it('emits one audit event per threshold crossing', function (): void {
    Cache::flush();
    ['user' => $user] = commercialPremiumUser();

    $service = app(CommercialThresholdNotificationService::class);
    $service->evaluate($user, 'max_api_keys', 8, 10, 'inventory');
    $service->evaluate($user, 'max_api_keys', 8, 10, 'inventory');

    expect(AuditLog::query()->where('action', 'commercial.usage_threshold_crossed')->count())->toBe(1);
});

it('crosses 80, 90, and 100 thresholds independently', function (): void {
    Cache::flush();
    ['user' => $user] = commercialPremiumUser();
    $service = app(CommercialThresholdNotificationService::class);

    $service->evaluate($user, 'webhook.max_endpoints', 8, 10, 'inventory');
    $service->evaluate($user, 'webhook.max_endpoints', 9, 10, 'inventory');
    $service->evaluate($user, 'webhook.max_endpoints', 10, 10, 'inventory');

    expect(AuditLog::query()->where('action', 'commercial.usage_threshold_crossed')->count())->toBe(3);
});

it('does not notify when the limit is zero', function (): void {
    Cache::flush();
    ['user' => $user] = commercialPremiumUser();

    app(CommercialThresholdNotificationService::class)->evaluate($user, 'max_api_keys', 0, 0, 'inventory');

    expect(AuditLog::query()->where('action', 'commercial.usage_threshold_crossed')->exists())->toBeFalse();
});
