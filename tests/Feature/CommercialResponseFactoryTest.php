<?php

use App\Exceptions\CommercialEntitlementDeniedException;
use App\Models\User;
use App\Services\Commercial\CommercialResponseFactory;
use Database\Seeders\CommercialPlanFeatureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CommercialPlanFeatureSeeder::class)->run();
});

it('builds unified feature denial envelopes with upgrade metadata', function (): void {
    $user = User::factory()->create();
    $response = TestResponse::fromBaseResponse(app(CommercialResponseFactory::class)->featureUnavailable('api.write', null, 403, $user));

    $response->assertForbidden()
        ->assertJsonPath('error.code', 'feature_not_available')
        ->assertJsonPath('error.details.feature', 'api.write')
        ->assertJsonPath('error.details.upgrade_required', true)
        ->assertJsonPath('error.details.recommended_plan', 'premium');
});

it('builds unified plan limit envelopes with remaining quota', function (): void {
    $response = TestResponse::fromBaseResponse(app(CommercialResponseFactory::class)->planLimitReached('max_api_keys', 5, 5));

    $response->assertStatus(409)
        ->assertJsonPath('error.code', 'plan_limit_reached')
        ->assertJsonPath('error.details.limit', 5)
        ->assertJsonPath('error.details.used', 5)
        ->assertJsonPath('error.details.remaining', 0)
        ->assertJsonPath('error.details.upgrade_required', true);
});

it('maps commercial entitlement denials consistently for inbox quota', function (): void {
    $user = User::factory()->create();
    $exception = new CommercialEntitlementDeniedException('inbox.max_active', 3, 3);
    $response = TestResponse::fromBaseResponse(app(CommercialResponseFactory::class)->fromCommercialEntitlementDenied($exception, $user));

    $response->assertForbidden()
        ->assertJsonPath('error.code', 'plan_limit_reached')
        ->assertJsonPath('error.details.feature', 'inbox.max_active')
        ->assertJsonPath('error.details.limit', 3)
        ->assertJsonPath('error.details.used', 3)
        ->assertJsonPath('error.details.remaining', 0);
});

it('maps boolean entitlement denials to feature_not_available', function (): void {
    $response = TestResponse::fromBaseResponse(app(CommercialResponseFactory::class)->fromCommercialEntitlementDenied(
        new CommercialEntitlementDeniedException('inbox.custom_alias'),
    ));

    $response->assertForbidden()->assertJsonPath('error.code', 'feature_not_available');
});

it('builds rate limit envelopes consistently', function (): void {
    $response = TestResponse::fromBaseResponse(app(CommercialResponseFactory::class)->rateLimitExceeded('api.max_requests_per_minute', 20, 0));

    $response->assertStatus(429)
        ->assertJsonPath('error.code', 'rate_limit_exceeded')
        ->assertJsonPath('error.details.feature', 'api.max_requests_per_minute')
        ->assertJsonPath('error.details.limit', 20)
        ->assertJsonPath('error.details.remaining', 0);
});
