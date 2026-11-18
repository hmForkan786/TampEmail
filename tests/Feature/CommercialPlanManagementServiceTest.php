<?php

use App\Enums\PlatformRole;
use App\Exceptions\CommercialManagementException;
use App\Models\Feature;
use App\Models\Plan;
use App\Models\User;
use App\Services\Commercial\CommercialPlanManagementService;
use Database\Seeders\CommercialPlanFeatureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => app(CommercialPlanFeatureSeeder::class)->run());

function commercialAdmin(): User
{
    return User::factory()->create(['platform_role' => PlatformRole::Admin]);
}

it('denies unauthorised plan mutation and protects canonical plan identity', function (): void {
    $free = Plan::query()->where('slug', 'free')->firstOrFail();
    $service = app(CommercialPlanManagementService::class);
    $expected = $free->updated_at->toIso8601String();
    expect(fn () => $service->updatePlan(User::factory()->create(), $free, ['name' => 'No'], $expected, 'test'))->toThrow(CommercialManagementException::class)
        ->and(fn () => $service->updatePlan(commercialAdmin(), $free, ['slug' => 'other'], $expected, 'test'))->toThrow(CommercialManagementException::class)
        ->and(fn () => $service->updatePlan(commercialAdmin(), $free, ['is_active' => false], $expected, 'test'))->toThrow(CommercialManagementException::class);
});

it('enforces typed mapping values and Free plan invariants', function (): void {
    $admin = commercialAdmin();
    $free = Plan::query()->where('slug', 'free')->firstOrFail();
    $service = app(CommercialPlanManagementService::class);
    $send = Feature::query()->where('key', 'send_email')->firstOrFail();
    $inboxes = Feature::query()->where('key', 'inbox.max_active')->firstOrFail();
    $expected = $free->updated_at->toIso8601String();
    expect(fn () => $service->updateFeatureValue($admin, $free, $send, ['enabled' => true], $expected, 'test'))->toThrow(CommercialManagementException::class)
        ->and(fn () => $service->updateFeatureValue($admin, $free, $inboxes, ['limit' => 0], $expected, 'test'))->toThrow(CommercialManagementException::class);
    $premium = Plan::query()->where('slug', 'premium')->firstOrFail();
    $service->updateFeatureValue($admin, $premium, $inboxes, ['limit' => 30], $premium->updated_at->toIso8601String(), 'capacity change');
    expect($premium->features()->whereKey($inboxes->id)->firstOrFail()->pivot->feature_value)->toBe(['limit' => 30]);
});

it('rejects stale plan writes instead of silently overwriting', function (): void {
    $admin = commercialAdmin();
    $premium = Plan::query()->where('slug', 'premium')->firstOrFail();
    expect(fn () => app(CommercialPlanManagementService::class)->updatePlan($admin, $premium, ['name' => 'Changed'], now()->subDay()->toIso8601String(), 'test'))->toThrow(CommercialManagementException::class);
});
