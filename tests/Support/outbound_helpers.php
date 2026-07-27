<?php

declare(strict_types=1);

use App\Actions\ApiKey\CreateApiKeyAction;
use App\Contracts\OutboundTransportInterface;
use App\DTOs\Outbound\OutboundDeliveryResult;
use App\Enums\BillingCycle;
use App\Enums\SubscriptionStatus;
use App\Enums\ValueType;
use App\Models\Domain;
use App\Models\Feature;
use App\Models\Inbox;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Outbound\FakeOutboundTransport;

if (! function_exists('outboundSendContext')) {
    /**
     * @return array{user: User, domain: Domain, inbox: Inbox, token: string, transport: FakeOutboundTransport}
     */
    function outboundSendContext(array $overrides = []): array
    {
        seedCommercialCatalogue();
        $user = User::factory()->create();
        $plan = Plan::query()->create([
            'slug' => 'outbound-'.uniqid(),
            'name' => 'Outbound Plan',
            'price_monthly' => '0.00',
            'price_yearly' => '0.00',
            'currency' => 'USD',
            'is_free' => true,
            'is_active' => true,
            'display_order' => 1,
        ]);

        $feature = Feature::query()->firstOrCreate(
            ['key' => 'send_email'],
            [
                'name' => 'Send Email',
                'value_type' => ValueType::Boolean,
                'default_value' => ['enabled' => true],
                'is_active' => true,
                'display_order' => 10,
            ],
        );
        $plan->features()->syncWithoutDetaching([
            $feature->id => ['feature_value' => ['enabled' => true]],
        ]);
        foreach (['outbound.schedule' => 'Schedule outbound', 'outbound.sender_profiles' => 'Sender profiles'] as $key => $name) {
            $commercialFeature = Feature::query()->firstOrCreate(
                ['key' => $key],
                ['name' => $name, 'value_type' => ValueType::Boolean, 'is_active' => true, 'display_order' => 11],
            );
            $plan->features()->syncWithoutDetaching([$commercialFeature->id => ['feature_value' => ['enabled' => true]]]);
        }
        $messageLimit = Feature::query()->firstOrCreate(
            ['key' => 'outbound_messages_per_period'],
            ['name' => 'Outbound messages', 'value_type' => ValueType::Json, 'is_active' => true, 'display_order' => 12],
        );
        $plan->features()->syncWithoutDetaching([$messageLimit->id => ['feature_value' => ['limit' => 1000, 'reset_period' => 'monthly']]]);
        attachApiCommercialFeatures($plan);

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
            'domain' => 'out-'.bin2hex(random_bytes(3)).'.test',
            'display_name' => 'Outbound',
            'is_active' => true,
            'is_public' => true,
            'allow_registration' => true,
            'is_healthy' => true,
            'outbound_enabled' => true,
            'retention_hours' => 24,
        ]);

        $inbox = Inbox::query()->create(array_merge([
            'domain_id' => $domain->id,
            'user_id' => $user->id,
            'local_part' => 'sender',
            'full_address' => 'sender@'.$domain->domain,
            'inbox_type' => 'temporary',
            'is_active' => true,
        ], $overrides['inbox'] ?? []));

        $token = app(CreateApiKeyAction::class)->issue(
            userId: $user->id,
            name: 'outbound-key',
            permissions: $overrides['scopes'] ?? ['outbound_messages:read', 'outbound_messages:write'],
            user: $user,
        )->plainToken;

        $transport = new FakeOutboundTransport(OutboundDeliveryResult::accepted('fake', 'fake-msg-1'));
        app()->instance(OutboundTransportInterface::class, $transport);

        return compact('user', 'domain', 'inbox', 'token', 'transport');
    }
}

if (! function_exists('outboundPayload')) {
    function outboundPayload(array $ctx, array $overrides = []): array
    {
        return array_merge([
            'inbox_id' => $ctx['inbox']->id,
            'idempotency_key' => 'idem-'.bin2hex(random_bytes(4)),
            'to' => ['recipient@example.test'],
            'subject' => 'Hello outbound',
            'text_body' => 'Plain body',
        ], $overrides);
    }
}
