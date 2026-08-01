<?php

declare(strict_types=1);

use App\Enums\AffiliateAttributionStatus;
use App\Models\AffiliateAttribution;
use App\Models\AnalyticsEvent;
use App\Models\User;
use App\Services\Affiliates\AffiliateAttributionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;

require_once __DIR__.'/../Support/affiliate_helpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'identity.password.uncompromised_check' => false,
        'identity.registration.mode' => 'open',
        'identity.registration.min_form_fill_ms' => 0,
        'identity.registration.email_verification_required' => true,
        'analytics.enabled' => true,
    ]);
    Notification::fake();
});

it('links affiliate attribution on registration without creating commission', function (): void {
    [$affiliateUser, $profile] = makeAffiliateContext();
    $visitor = bin2hex(random_bytes(32));

    $request = Request::create('/', 'GET', ['ref' => $profile->affiliate_code]);
    app(AffiliateAttributionService::class)->recordClick($profile->affiliate_code, $request, $visitor);

    $cookie = (string) config('affiliates.cookie.name', 'temail_aff');

    $this->withCookie($cookie, $visitor)
        ->post(route('register.store'), [
            'name' => 'Referred User',
            'email' => 'referred@example.test',
            'password' => 'Str0ng!Passw0rd',
            'password_confirmation' => 'Str0ng!Passw0rd',
            'terms_accepted' => '1',
            '_form_started_at' => (int) (microtime(true) * 1000) - 5000,
        ])
        ->assertRedirect();

    $user = User::query()->where('email', 'referred@example.test')->firstOrFail();
    $attribution = AffiliateAttribution::query()
        ->where('converted_user_id', $user->id)
        ->first();

    expect($attribution)->not->toBeNull()
        ->and($attribution->status)->toBe(AffiliateAttributionStatus::Converted);

    expect(\App\Models\AffiliateCommissionEntry::query()->count())->toBe(0);
});

it('emits PII-safe identity analytics events on registration', function (): void {
    $this->post(route('register.store'), [
        'name' => 'Analytics User',
        'email' => 'analytics-user@example.test',
        'password' => 'Str0ng!Passw0rd',
        'password_confirmation' => 'Str0ng!Passw0rd',
        'terms_accepted' => '1',
        '_form_started_at' => (int) (microtime(true) * 1000) - 5000,
    ])->assertRedirect();

    $events = AnalyticsEvent::query()->where('source_event', 'identity.registration_completed')->get();
    expect($events)->not->toBeEmpty();

    foreach ($events as $event) {
        $dimensions = $event->dimensions ?? [];
        expect($dimensions)->not->toHaveKey('email')
            ->and($dimensions)->not->toHaveKey('password')
            ->and($dimensions)->not->toHaveKey('ip');
    }
});
