<?php

declare(strict_types=1);

use App\Enums\NotificationPreferenceCategory;
use App\Enums\NotificationPreferenceChannel;
use App\Models\User;
use App\Models\UserNotificationPreference;
use App\Services\Settings\NotificationPreferenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('creates unique notification preference defaults', function (): void {
    $user = User::factory()->create();
    $service = app(NotificationPreferenceService::class);
    $service->ensureDefaults($user);
    $service->ensureDefaults($user);

    expect(UserNotificationPreference::query()->where('user_id', $user->id)->count())
        ->toBe(count(NotificationPreferenceCategory::cases()) * count(NotificationPreferenceChannel::cases()));
});

it('cannot disable critical security notifications', function (): void {
    $user = User::factory()->create();
    $service = app(NotificationPreferenceService::class);

    expect(fn () => $service->updateMany($user, [[
        'category' => 'security',
        'channel' => 'email',
        'enabled' => false,
    ]]))->toThrow(ValidationException::class);
});

it('rejects unknown notification categories', function (): void {
    $user = User::factory()->create();
    $service = app(NotificationPreferenceService::class);

    expect(fn () => $service->updateMany($user, [[
        'category' => 'not-a-real-category',
        'channel' => 'email',
        'enabled' => true,
    ]]))->toThrow(ValidationException::class);
});
