<?php

use App\Models\AuditLog;
use App\Models\Feature;
use App\Models\OutboundMessage;
use App\Models\OutboundUsageReservation;
use App\Models\Subscription;
use App\Services\Entitlement\EntitlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['api.key_hash_secret' => 'outbound-commercial-test-secret', 'outbound.enabled' => true, 'outbound.send_enabled' => true, 'outbound.reply_enabled' => true, 'outbound.forward_enabled' => true, 'outbound.rollout.mode' => 'enabled', 'outbound.rollout.emergency_stop' => false, 'queue.default' => 'sync']);
});

it('denies a missing send entitlement before creating a message or reservation', function (): void {
    $ctx = outboundSendContext();
    $feature = Feature::query()->where('key', 'send_email')->sole();
    Subscription::query()->where('user_id', $ctx['user']->id)->firstOrFail()->plan->features()->updateExistingPivot($feature->id, ['feature_value' => ['enabled' => false]]);

    $this->withToken($ctx['token'])->postJson('/api/v1/outbound-messages', outboundPayload($ctx))
        ->assertForbidden()->assertJsonPath('error.code', 'feature_not_available');

    expect(OutboundMessage::query()->count())->toBe(0)
        ->and(OutboundUsageReservation::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'commercial.outbound_feature_denied')->exists())->toBeTrue();
});

it('falls back to Free and denies outbound access when an active subscription expires', function (): void {
    $ctx = outboundSendContext();
    $subscription = Subscription::query()->where('user_id', $ctx['user']->id)->firstOrFail();
    $subscription->forceFill(['ends_at' => now()])->save();

    $this->withToken($ctx['token'])->postJson('/api/v1/outbound-messages', outboundPayload($ctx))
        ->assertForbidden()->assertJsonPath('error.code', 'feature_not_available');
    expect(app(EntitlementService::class)->effectivePlan($ctx['user'])?->slug)->toBe('free');
});
