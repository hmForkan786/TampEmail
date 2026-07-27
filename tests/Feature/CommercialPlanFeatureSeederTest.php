<?php

use App\Enums\ValueType;
use App\Models\Feature;
use App\Models\Plan;
use Database\Seeders\CommercialPlanFeatureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function seedCommercialCatalogue(): void
{
    app(CommercialPlanFeatureSeeder::class)->run();
}

function commercialValue(Plan $plan, string $key): array
{
    return $plan->fresh()->features()->where('key', $key)->firstOrFail()->pivot->feature_value;
}

it('creates the canonical active free and premium plans', function (): void {
    seedCommercialCatalogue();

    $free = Plan::query()->where('slug', CommercialPlanFeatureSeeder::FREE_PLAN)->firstOrFail();
    $premium = Plan::query()->where('slug', CommercialPlanFeatureSeeder::PREMIUM_PLAN)->firstOrFail();

    expect($free->is_active)->toBeTrue()->and($free->is_free)->toBeTrue()
        ->and($free->metadata['default_fallback'])->toBeTrue()
        ->and($premium->is_active)->toBeTrue()->and($premium->is_free)->toBeFalse();
});

it('creates every commercial feature with its declared value type', function (): void {
    seedCommercialCatalogue();

    $types = [
        'inbox.create' => ValueType::Boolean, 'max_inboxes' => ValueType::Integer,
        'inbox.custom_alias' => ValueType::Boolean, 'inbox.public_access' => ValueType::Boolean,
        'inbox.retention_hours' => ValueType::Integer, 'message.max_received' => ValueType::Integer,
        'attachment.download' => ValueType::Boolean, 'attachment.max_size_mb' => ValueType::Integer,
        'attachment.max_per_message' => ValueType::Integer, 'send_email' => ValueType::Boolean,
        'reply_email' => ValueType::Boolean, 'forward_email' => ValueType::Boolean,
        'outbound.schedule' => ValueType::Boolean, 'outbound.sender_profiles' => ValueType::Boolean,
        'outbound_messages_per_period' => ValueType::Json, 'outbound_recipients_per_period' => ValueType::Json,
        'outbound_attachment_bytes_per_period' => ValueType::Json, 'outbound_retention_days' => ValueType::Json,
        'api.read' => ValueType::Boolean, 'api.write' => ValueType::Boolean,
        'api.max_requests_per_minute' => ValueType::Integer, 'max_api_keys' => ValueType::Integer,
        'webhook.access' => ValueType::Boolean, 'webhook.max_endpoints' => ValueType::Integer,
        'ads.visible' => ValueType::Boolean, 'analytics.basic' => ValueType::Boolean,
        'analytics.advanced' => ValueType::Boolean, 'priority.processing' => ValueType::Boolean,
        'support.priority' => ValueType::Boolean, 'mail_server_pools' => ValueType::Json,
    ];

    foreach ($types as $key => $type) {
        expect(Feature::query()->where('key', $key)->firstOrFail()->value_type)->toBe($type);
    }
});

it('maps the commercial access boundary exactly', function (): void {
    seedCommercialCatalogue();
    $free = Plan::query()->where('slug', 'free')->firstOrFail();
    $premium = Plan::query()->where('slug', 'premium')->firstOrFail();

    expect(commercialValue($free, 'max_inboxes'))->toBe(['limit' => 3])
        ->and(commercialValue($premium, 'max_inboxes'))->toBe(['limit' => 25])
        ->and(commercialValue($free, 'send_email'))->toBe(['enabled' => false])
        ->and(commercialValue($premium, 'send_email'))->toBe(['enabled' => true])
        ->and(commercialValue($free, 'inbox.custom_alias'))->toBe(['enabled' => false])
        ->and(commercialValue($free, 'api.write'))->toBe(['enabled' => false])
        ->and(commercialValue($free, 'webhook.access'))->toBe(['enabled' => false])
        ->and(commercialValue($free, 'ads.visible'))->toBe(['enabled' => true])
        ->and(commercialValue($premium, 'ads.visible'))->toBe(['enabled' => false])
        ->and(commercialValue($free, 'outbound_messages_per_period'))->toBe(['limit' => 0, 'reset_period' => 'monthly'])
        ->and(commercialValue($premium, 'outbound_messages_per_period'))->toBe(['limit' => 1000, 'reset_period' => 'monthly']);
});

it('is idempotent and preserves unrelated custom catalogue records', function (): void {
    $customPlan = Plan::query()->create(['slug' => 'custom', 'name' => 'Custom', 'price_monthly' => '1.00', 'price_yearly' => '10.00', 'currency' => 'USD', 'is_free' => false, 'is_active' => true, 'display_order' => 99]);
    $customFeature = Feature::query()->create(['key' => 'custom.feature', 'name' => 'Custom', 'value_type' => ValueType::Boolean, 'is_active' => true, 'display_order' => 99]);
    $customPlan->features()->attach($customFeature->id, ['feature_value' => ['enabled' => true]]);

    seedCommercialCatalogue();
    seedCommercialCatalogue();

    expect(Plan::query()->where('slug', 'free')->count())->toBe(1)
        ->and(Plan::query()->where('slug', 'premium')->count())->toBe(1)
        ->and(Feature::query()->where('key', 'inbox.create')->count())->toBe(1)
        ->and($customPlan->fresh())->not->toBeNull()
        ->and($customFeature->fresh())->not->toBeNull()
        ->and($customPlan->fresh()->features()->whereKey($customFeature->id)->count())->toBe(1);
});
