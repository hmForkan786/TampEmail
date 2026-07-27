<?php

declare(strict_types=1);

use App\Actions\ApiKey\CreateApiKeyAction;
use App\Enums\BillingCycle;
use App\Enums\SubscriptionStatus;
use App\Enums\ValueType;
use App\Models\ApiKey;
use App\Models\Feature;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\CommercialPlanFeatureSeeder;

if (! function_exists('seedCommercialCatalogue')) {
    function seedCommercialCatalogue(): void
    {
        app(CommercialPlanFeatureSeeder::class)->run();
    }
}

if (! function_exists('commercialPremiumUser')) {
    /** @return array{user: User, plan: Plan} */
    function commercialPremiumUser(): array
    {
        seedCommercialCatalogue();
        $user = User::factory()->create();
        $plan = Plan::query()->where('slug', 'premium')->sole();
        Subscription::query()->create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::Active,
            'billing_cycle' => BillingCycle::Monthly,
            'starts_at' => now()->subMinute(),
            'auto_renew' => true,
            'price' => '9.00',
            'currency' => 'USD',
        ]);

        return compact('user', 'plan');
    }
}

if (! function_exists('grantApiRead')) {
    function grantApiRead(User $user, ?Plan $plan = null): void
    {
        $plan ??= Subscription::query()->where('user_id', $user->id)->first()?->plan
            ?? Plan::query()->where('slug', 'free')->sole();
        $feature = Feature::query()->where('key', 'api.read')->sole();
        $plan->features()->updateExistingPivot($feature->id, ['feature_value' => ['enabled' => true]]);
    }
}

if (! function_exists('grantApiWrite')) {
    function grantApiWrite(User $user, ?Plan $plan = null): void
    {
        $plan ??= Subscription::query()->where('user_id', $user->id)->first()?->plan
            ?? Plan::query()->where('slug', 'free')->sole();
        $feature = Feature::query()->where('key', 'api.write')->sole();
        $plan->features()->updateExistingPivot($feature->id, ['feature_value' => ['enabled' => true]]);
    }
}

if (! function_exists('grantWebhookAccess')) {
    function grantWebhookAccess(User $user, ?Plan $plan = null): void
    {
        $plan ??= Subscription::query()->where('user_id', $user->id)->first()?->plan
            ?? Plan::query()->where('slug', 'premium')->sole();
        $feature = Feature::query()->where('key', 'webhook.access')->sole();
        $plan->features()->updateExistingPivot($feature->id, ['feature_value' => ['enabled' => true]]);
    }
}

if (! function_exists('setApiKeyLimit')) {
    function setApiKeyLimit(User $user, int $limit, ?Plan $plan = null): void
    {
        $plan ??= Subscription::query()->where('user_id', $user->id)->firstOrFail()->plan;
        $feature = Feature::query()->where('key', 'max_api_keys')->sole();
        $plan->features()->updateExistingPivot($feature->id, ['feature_value' => ['limit' => $limit]]);
    }
}

if (! function_exists('setApiRateLimit')) {
    function setApiRateLimit(User $user, int $limit, ?Plan $plan = null): void
    {
        $plan ??= Subscription::query()->where('user_id', $user->id)->firstOrFail()->plan;
        $feature = Feature::query()->where('key', 'api.max_requests_per_minute')->sole();
        $plan->features()->updateExistingPivot($feature->id, ['feature_value' => ['limit' => $limit]]);
    }
}

if (! function_exists('setWebhookEndpointLimit')) {
    function setWebhookEndpointLimit(User $user, int $limit, ?Plan $plan = null): void
    {
        $plan ??= Subscription::query()->where('user_id', $user->id)->firstOrFail()->plan;
        $feature = Feature::query()->where('key', 'webhook.max_endpoints')->sole();
        $plan->features()->updateExistingPivot($feature->id, ['feature_value' => ['limit' => $limit]]);
    }
}

if (! function_exists('attachApiCommercialFeatures')) {
    function attachApiCommercialFeatures(Plan $plan, int $rateLimit = 120): void
    {
        seedCommercialCatalogue();

        foreach ([
            'api.read' => ['enabled' => true],
            'api.write' => ['enabled' => true],
            'api.max_requests_per_minute' => ['limit' => $rateLimit],
        ] as $key => $value) {
            $feature = Feature::query()->where('key', $key)->sole();
            $plan->features()->syncWithoutDetaching([
                $feature->id => ['feature_value' => $value],
            ]);
        }
    }
}

if (! function_exists('commercialApiUser')) {
    /** @param  list<string>  $permissions
     * @return array{user: User, plan: Plan, token: string} */
    function commercialApiUser(array $permissions = ['outbound_messages:write']): array
    {
        $user = User::factory()->create();
        $plan = Plan::create([
            'slug' => 'commercial-api-'.uniqid(),
            'name' => 'Commercial API user',
            'price_monthly' => '0.00',
            'price_yearly' => '0.00',
            'currency' => 'USD',
            'is_free' => true,
            'is_active' => true,
            'display_order' => 1,
        ]);
        attachApiCommercialFeatures($plan);
        Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::Active,
            'billing_cycle' => BillingCycle::Monthly,
            'starts_at' => now()->subDay(),
            'auto_renew' => true,
            'price' => '0.00',
            'currency' => 'USD',
        ]);
        $token = app(CreateApiKeyAction::class)->issue(
            userId: $user->id,
            name: 'commercial-api-user',
            permissions: $permissions,
            user: $user,
        )->plainToken;

        return compact('user', 'plan', 'token');
    }
}

if (! function_exists('ensureFreeCommercialUser')) {
    function ensureFreeCommercialUser(User $user, int $apiKeyLimit = 50): void
    {
        seedCommercialCatalogue();

        if (! Subscription::query()->where('user_id', $user->id)->exists()) {
            Subscription::query()->create([
                'user_id' => $user->id,
                'plan_id' => Plan::query()->where('slug', 'free')->sole()->id,
                'status' => SubscriptionStatus::Active,
                'billing_cycle' => BillingCycle::Monthly,
                'starts_at' => now()->subDay(),
                'auto_renew' => true,
                'price' => '0.00',
                'currency' => 'USD',
            ]);
        }

        setApiKeyLimit($user, $apiKeyLimit);
    }
}

if (! function_exists('ensureCommercialApiAccess')) {
    /** @param  list<string>|null  $permissions */
    function ensureCommercialApiAccess(User $user, ?array $permissions = null, bool $grantWrite = true): void
    {
        if (! $grantWrite || $permissions === null) {
            return;
        }

        foreach ($permissions as $permission) {
            if (! is_string($permission)) {
                continue;
            }

            if (str_ends_with($permission, ':write') || str_ends_with($permission, ':admin')) {
                grantApiWrite($user);

                return;
            }
        }
    }
}

if (! function_exists('issueCommercialApiKey')) {
    /** @param  list<string>|null  $permissions */
    function issueCommercialApiKey(
        User $user,
        ?array $permissions = null,
        string $name = 'commercial-api',
        bool $grantCommercial = true,
    ): string {
        if ($grantCommercial) {
            ensureCommercialApiAccess($user, $permissions);
        }

        return app(CreateApiKeyAction::class)->issue(
            userId: $user->id,
            name: $name,
            permissions: $permissions ?? ['outbound_messages:read', 'outbound_messages:write'],
            user: $user,
        )->plainToken;
    }
}

if (! function_exists('premiumWebhookFixture')) {
    /** @return array{user: User, token: string} */
    function premiumWebhookFixture(): array
    {
        ['user' => $user] = commercialPremiumUser();
        $token = issueCommercialApiKey($user);

        return compact('user', 'token');
    }
}

if (! function_exists('apiKeyQuotaUser')) {
    function apiKeyQuotaUser(?int $limit): User
    {
        $user = User::factory()->create();
        $plan = Plan::create([
            'slug' => 'api-key-quota-'.uniqid(),
            'name' => 'API key quota',
            'price_monthly' => '0.00',
            'price_yearly' => '0.00',
            'currency' => 'USD',
            'is_free' => true,
            'is_active' => true,
            'display_order' => 1,
        ]);
        $feature = Feature::query()->firstOrCreate(
            ['key' => 'max_api_keys'],
            ['name' => 'Max API keys', 'value_type' => ValueType::Json, 'is_active' => true, 'display_order' => 1],
        );
        $plan->features()->attach($feature->id, ['feature_value' => ['limit' => $limit]]);
        Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::Active,
            'billing_cycle' => BillingCycle::Monthly,
            'starts_at' => now()->subDay(),
            'auto_renew' => true,
            'price' => '0.00',
            'currency' => 'USD',
        ]);

        return $user;
    }
}

if (! function_exists('executeApiKeyIssue')) {
    function executeApiKeyIssue(User $user, string $name = 'quota-key'): ApiKey
    {
        return app(CreateApiKeyAction::class)->issue(
            userId: $user->id,
            name: $name,
            user: $user,
        )->apiKey;
    }
}

if (! function_exists('webhookPayload')) {
    /** @return array<string, mixed> */
    function webhookPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Primary endpoint',
            'url' => 'https://example.com/webhooks/temail',
            'events' => ['outbound.message.sent'],
            'is_active' => true,
        ], $overrides);
    }
}
