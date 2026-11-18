<?php

declare(strict_types=1);

use App\Enums\AffiliateProfileStatus;
use App\Exceptions\Affiliates\AffiliateNotEligibleException;
use App\Exceptions\Affiliates\AffiliateRegistrationException;
use App\Models\User;
use App\Services\Affiliates\AffiliateRegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Support/affiliate_helpers.php';

beforeEach(function (): void {
    enableAffiliates();
    seedAffiliatePlan();
});

it('applies successfully into pending under manual approval', function (): void {
    $user = User::factory()->create();
    $profile = app(AffiliateRegistrationService::class)->apply($user, [
        'promotion_channel' => 'youtube',
        'audience_description' => 'SaaS buyers',
    ]);

    expect($profile->status)->toBe(AffiliateProfileStatus::Pending)
        ->and($profile->affiliate_code)->not->toBeEmpty()
        ->and($profile->user_id)->toBe($user->getKey());
});

it('returns the same pending profile on duplicate apply', function (): void {
    $user = User::factory()->create();
    $service = app(AffiliateRegistrationService::class);
    $first = $service->apply($user, ['promotion_channel' => 'blog']);
    $second = $service->apply($user, ['promotion_channel' => 'blog']);

    expect($second->getKey())->toBe($first->getKey());
});

it('rejects apply when module is disabled', function (): void {
    config(['affiliates.enabled' => false]);
    $user = User::factory()->create();

    expect(fn () => app(AffiliateRegistrationService::class)->apply($user, []))
        ->toThrow(AffiliateNotEligibleException::class);
});

it('approves rejects suspends and reactivates via admin', function (): void {
    $user = User::factory()->create();
    $admin = User::factory()->platformAdmin()->create();
    $service = app(AffiliateRegistrationService::class);

    $profile = $service->apply($user, ['promotion_channel' => 'x']);
    $approved = $service->approve($profile, $admin);
    expect($approved->status)->toBe(AffiliateProfileStatus::Active);

    $suspended = $service->suspend($approved, $admin, 'fraud');
    expect($suspended->status)->toBe(AffiliateProfileStatus::Suspended)
        ->and($suspended->canReceiveAttribution())->toBeFalse()
        ->and($suspended->canWithdraw())->toBeFalse();

    $reactivated = $service->reactivate($suspended, $admin);
    expect($reactivated->status)->toBe(AffiliateProfileStatus::Active);

    $pending = $service->apply(User::factory()->create(), []);
    $rejected = $service->reject($pending, $admin, 'low quality');
    expect($rejected->status)->toBe(AffiliateProfileStatus::Rejected);
});

it('rejects apply when registration mode is disabled', function (): void {
    config(['affiliates.registration_mode' => 'disabled']);
    $user = User::factory()->create();

    expect(fn () => app(AffiliateRegistrationService::class)->apply($user, []))
        ->toThrow(AffiliateRegistrationException::class);
});
