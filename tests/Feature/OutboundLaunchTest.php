<?php

declare(strict_types=1);

use App\Enums\BillingCycle;
use App\Enums\OutboundCanarySubjectType;
use App\Enums\OutboundMessageState;
use App\Enums\OutboundOperation;
use App\Enums\SubscriptionStatus;
use App\Enums\ValueType;
use App\Exceptions\OutboundSendException;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\Feature;
use App\Models\Inbox;
use App\Models\OutboundMessage;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Outbound\OutboundAuthorizationService;
use App\Services\Outbound\OutboundCanaryService;
use App\Services\Outbound\OutboundLaunchConfigValidator;
use App\Services\Outbound\OutboundLaunchControlService;
use App\Services\Outbound\OutboundLaunchReadinessService;
use App\Services\Outbound\OutboundLaunchRecommendationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

function launchContext(): array
{
    $user = User::factory()->create();
    $plan = Plan::query()->create([
        'slug' => 'launch-'.uniqid(),
        'name' => 'Launch Plan',
        'price_monthly' => '0.00',
        'price_yearly' => '0.00',
        'currency' => 'USD',
        'is_free' => true,
        'is_active' => true,
        'display_order' => 1,
    ]);
    foreach (['send_email', 'reply_email', 'forward_email'] as $key) {
        $feature = Feature::query()->firstOrCreate(
            ['key' => $key],
            [
                'name' => $key,
                'value_type' => ValueType::Boolean,
                'default_value' => ['enabled' => true],
                'is_active' => true,
                'display_order' => 10,
            ],
        );
        $plan->features()->syncWithoutDetaching([
            $feature->id => ['feature_value' => ['enabled' => true]],
        ]);
    }
    Subscription::query()->create([
        'user_id' => $user->id,
        'plan_id' => $plan->id,
        'status' => SubscriptionStatus::Active,
        'billing_cycle' => BillingCycle::Monthly,
        'starts_at' => now()->subDay(),
        'auto_renew' => true,
        'price' => '0.00',
        'currency' => 'USD',
    ]);

    $domain = Domain::query()->create([
        'domain' => 'launch-'.bin2hex(random_bytes(3)).'.test',
        'display_name' => 'Launch',
        'is_active' => true,
        'is_public' => true,
        'allow_registration' => true,
        'is_healthy' => true,
        'outbound_enabled' => true,
        'retention_hours' => 24,
    ]);
    $inbox = Inbox::query()->create([
        'domain_id' => $domain->id,
        'user_id' => $user->id,
        'local_part' => 'sender',
        'full_address' => 'sender@'.$domain->domain,
        'inbox_type' => 'temporary',
        'is_active' => true,
    ]);

    return compact('user', 'domain', 'inbox');
}

beforeEach(function (): void {
    Cache::flush();
    config([
        'outbound.enabled' => true,
        'outbound.send_enabled' => true,
        'outbound.reply_enabled' => true,
        'outbound.forward_enabled' => true,
        'outbound.rollout.mode' => 'disabled',
        'outbound.rollout.percent' => 0,
        'outbound.rollout.emergency_stop' => false,
        'outbound.domain_authentication.enforce' => false,
        'queue.default' => 'sync',
    ]);
});

it('defaults to disabled rollout and rejects unsupported modes', function (): void {
    $ctx = launchContext();
    $control = app(OutboundLaunchControlService::class);

    expect($control->mode())->toBe('disabled');
    expect(fn () => $control->assertRolloutEligible($ctx['user'], $ctx['inbox']))
        ->toThrow(OutboundSendException::class);

    config(['outbound.rollout.mode' => 'not-a-mode']);
    expect($control->isSupportedMode())->toBeFalse();
    expect(fn () => $control->assertRolloutEligible($ctx['user'], $ctx['inbox']))
        ->toThrow(OutboundSendException::class);
});

it('lets emergency stop override enabled mode without failing queued messages', function (): void {
    config([
        'outbound.rollout.mode' => 'enabled',
        'outbound.rollout.emergency_stop' => true,
    ]);
    $ctx = launchContext();
    $control = app(OutboundLaunchControlService::class);

    expect($control->isEmergencyStopped())->toBeTrue();
    expect(fn () => $control->assertRolloutEligible($ctx['user'], $ctx['inbox']))
        ->toThrow(OutboundSendException::class);

    $message = OutboundMessage::query()->create([
        'user_id' => $ctx['user']->id,
        'inbox_id' => $ctx['inbox']->id,
        'operation' => OutboundOperation::Send,
        'state' => OutboundMessageState::Queued,
        'idempotency_key' => 'launch-em-'.uniqid(),
        'request_fingerprint' => hash('sha256', 'launch-em'),
        'from_address' => $ctx['inbox']->full_address,
        'to_recipients' => ['to@example.test'],
        'subject' => 'Hello',
        'text_body' => 'Body',
        'attempt_count' => 0,
        'queued_at' => now(),
    ]);

    // Policy: emergency stop never marks queued work failed.
    expect($message->fresh()->state)->toBe(OutboundMessageState::Queued)
        ->and($message->fresh()->failure_code)->toBeNull();
});

it('allows canary users and denies non-canaries without bypassing domain auth', function (): void {
    config([
        'outbound.rollout.mode' => 'canary',
        'outbound.rollout.emergency_stop' => false,
        'outbound.domain_authentication.enforce' => true,
        'outbound.provider' => 'ses',
        'outbound.domain_authentication.ses.dkim_tokens' => 'tok1',
    ]);
    $ctx = launchContext();
    $admin = User::factory()->platformAdmin()->create();
    app(OutboundCanaryService::class)->add(
        OutboundCanarySubjectType::User,
        (string) $ctx['user']->getKey(),
        $admin,
        'launch',
    );

    expect(AuditLog::query()->where('action', 'outbound.canary_added')->exists())->toBeTrue();

    expect(fn () => app(OutboundAuthorizationService::class)->assertCanSend($ctx['user'], $ctx['inbox']))
        ->toThrow(OutboundSendException::class);

    config(['outbound.domain_authentication.enforce' => false]);
    app(OutboundAuthorizationService::class)->assertCanSend($ctx['user'], $ctx['inbox']);

    $other = launchContext();
    expect(fn () => app(OutboundLaunchControlService::class)
        ->assertRolloutEligible($other['user'], $other['inbox']))
        ->toThrow(OutboundSendException::class);
});

it('assigns percentage rollout deterministically within boundaries', function (): void {
    config([
        'outbound.rollout.mode' => 'percentage',
        'outbound.rollout.percent' => 0,
        'outbound.rollout.emergency_stop' => false,
    ]);
    $ctx = launchContext();
    $control = app(OutboundLaunchControlService::class);
    expect(fn () => $control->assertRolloutEligible($ctx['user'], $ctx['inbox']))
        ->toThrow(OutboundSendException::class);

    config(['outbound.rollout.percent' => 100]);
    $control->assertRolloutEligible($ctx['user'], $ctx['inbox']);

    config(['outbound.rollout.percent' => 50]);
    $first = $control->isEligible($ctx['user'], $ctx['inbox']);
    $second = $control->isEligible($ctx['user'], $ctx['inbox']);
    expect($first)->toBe($second);
});

it('reports launch readiness states and secret-free JSON', function (): void {
    config([
        'outbound.rollout.mode' => 'disabled',
        'outbound.rollout.emergency_stop' => true,
        'outbound.transport' => 'unavailable',
    ]);

    $report = app(OutboundLaunchReadinessService::class)->evaluate();
    expect($report)->toHaveKeys(['status', 'checks', 'reasons'])
        ->and($report['checks'])->toHaveKey('rollout')
        ->and($report['status'])->toBeIn(['ready', 'degraded', 'blocked', 'disabled'])
        ->and(json_encode($report))->not->toContain('"password"')
        ->and(json_encode($report))->not->toContain('secret_value');

    $this->artisan('outbound:launch-readiness', ['--json' => true])
        ->assertExitCode(2);
});

it('produces advisory continue hold or rollback recommendations', function (): void {
    $result = app(OutboundLaunchRecommendationService::class)->recommend();
    expect($result['recommendation'])->toBeIn(['continue', 'hold', 'rollback'])
        ->and($result)->toHaveKey('reasons')
        ->and($result)->toHaveKey('metrics');
});

it('audits emergency stop changes and gates canary-send command', function (): void {
    $admin = User::factory()->platformAdmin()->create();
    app(OutboundLaunchControlService::class)->setEmergencyStop(true, $admin);
    expect(AuditLog::query()->where('action', 'outbound.launch_emergency_stop_changed')->exists())->toBeTrue();

    config(['outbound.launch.canary_send.enabled' => false]);
    $this->artisan('outbound:canary-send')->assertFailed();
});

it('validates rollout configuration without rewriting env', function (): void {
    config([
        'outbound.rollout.mode' => 'percentage',
        'outbound.rollout.percent' => 150,
        'outbound.rollout.emergency_stop' => false,
    ]);
    $validation = app(OutboundLaunchConfigValidator::class)->validate();
    expect($validation['valid'])->toBeFalse()
        ->and($validation['errors'])->toContain('rollout_percent_out_of_range');

    config([
        'outbound.rollout.mode' => 'canary',
        'outbound.rollout.percent' => 0,
    ]);
    $validation2 = app(OutboundLaunchConfigValidator::class)->validate();
    expect($validation2['errors'])->toContain('canary_mode_without_canaries');
});
