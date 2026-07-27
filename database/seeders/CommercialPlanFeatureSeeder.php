<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\ValueType;
use App\Models\Feature;
use App\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * Provisions the managed Free and Premium commercial catalogue.
 *
 * Only the two canonical plans and their explicitly listed feature mappings
 * are managed. Custom plans, custom features, subscriptions, and unrelated
 * mappings are intentionally left untouched.
 */
class CommercialPlanFeatureSeeder extends Seeder
{
    public const FREE_PLAN = 'free';

    public const PREMIUM_PLAN = 'premium';

    public function run(): void
    {
        // Retain the existing outbound catalogue and its stable legacy keys.
        $this->call(FeatureSeeder::class);

        $features = [];
        foreach ($this->features() as $definition) {
            $features[$definition['key']] = Feature::query()->updateOrCreate(
                ['key' => $definition['key']],
                $definition,
            );
        }

        foreach ($this->plans() as $definition) {
            $plan = Plan::query()->updateOrCreate(['slug' => $definition['slug']], $definition);
            $this->attachManagedMappings($plan, $features);
        }
    }

    /** @return list<array<string, mixed>> */
    private function plans(): array
    {
        return [
            ['slug' => self::FREE_PLAN, 'name' => 'Free', 'description' => 'Entry-level commercial plan.', 'price_monthly' => '0.00', 'price_yearly' => '0.00', 'currency' => 'USD', 'is_free' => true, 'is_active' => true, 'display_order' => 10, 'metadata' => ['public' => true, 'default_fallback' => true]],
            ['slug' => self::PREMIUM_PLAN, 'name' => 'Premium', 'description' => 'Commercial premium plan.', 'price_monthly' => '9.00', 'price_yearly' => '90.00', 'currency' => 'USD', 'is_free' => false, 'is_active' => true, 'display_order' => 20, 'metadata' => ['public' => true, 'default_fallback' => false]],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function features(): array
    {
        return [
            $this->feature('inbox.create', 'Create inbox', ValueType::Boolean, 'inbox', 100),
            $this->feature('max_inboxes', 'Maximum active inboxes', ValueType::Integer, 'inbox', 101),
            $this->feature('inbox.custom_alias', 'Custom inbox aliases', ValueType::Boolean, 'inbox', 102),
            $this->feature('inbox.public_access', 'Public inbox access', ValueType::Boolean, 'inbox', 103),
            $this->feature('inbox.retention_hours', 'Inbox retention hours', ValueType::Integer, 'inbox', 104),
            $this->feature('message.max_received', 'Maximum received messages', ValueType::Integer, 'inbox', 105),
            $this->feature('attachment.download', 'Attachment download', ValueType::Boolean, 'attachment', 110),
            $this->feature('attachment.max_size_mb', 'Maximum attachment size (MB)', ValueType::Integer, 'attachment', 111),
            $this->feature('attachment.max_per_message', 'Maximum attachments per message', ValueType::Integer, 'attachment', 112),
            $this->feature('outbound.schedule', 'Schedule outbound email', ValueType::Boolean, 'outbound', 120),
            $this->feature('outbound.sender_profiles', 'Outbound sender profiles', ValueType::Boolean, 'outbound', 121),
            $this->feature('api.read', 'API read access', ValueType::Boolean, 'api', 130),
            $this->feature('api.write', 'API write access', ValueType::Boolean, 'api', 131),
            $this->feature('api.max_requests_per_minute', 'API requests per minute', ValueType::Integer, 'api', 132),
            $this->feature('max_api_keys', 'Maximum active API keys', ValueType::Integer, 'api', 133),
            $this->feature('webhook.access', 'User webhook access', ValueType::Boolean, 'webhook', 140),
            $this->feature('webhook.max_endpoints', 'Maximum webhook endpoints', ValueType::Integer, 'webhook', 141),
            $this->feature('ads.visible', 'Advertising visibility', ValueType::Boolean, 'commercial', 150),
            $this->feature('analytics.basic', 'Basic analytics', ValueType::Boolean, 'commercial', 151),
            $this->feature('analytics.advanced', 'Advanced analytics', ValueType::Boolean, 'commercial', 152),
            $this->feature('priority.processing', 'Priority processing', ValueType::Boolean, 'service', 160),
            $this->feature('support.priority', 'Priority support', ValueType::Boolean, 'service', 161),
            $this->feature('mail_server_pools', 'Eligible mail server pools', ValueType::Json, 'inbox', 106),
        ];
    }

    /** @return array<string, mixed> */
    private function feature(string $key, string $name, ValueType $type, string $category, int $displayOrder): array
    {
        return ['key' => $key, 'name' => $name, 'description' => "Commercial entitlement: {$name}.", 'category' => $category, 'value_type' => $type->value, 'default_value' => null, 'is_active' => true, 'display_order' => $displayOrder, 'metadata' => ['managed_by' => 'commercial_catalogue']];
    }

    /** @param array<string, Feature> $features */
    private function attachManagedMappings(Plan $plan, array $features): void
    {
        $premium = $plan->slug === self::PREMIUM_PLAN;
        $values = [
            'inbox.create' => $this->enabled(true), 'max_inboxes' => $this->limit($premium ? 25 : 3), 'inbox.custom_alias' => $this->enabled($premium), 'inbox.public_access' => $this->enabled(true), 'inbox.retention_hours' => $this->limit($premium ? 720 : 24), 'message.max_received' => $this->limit($premium ? 5000 : 100),
            'attachment.download' => $this->enabled(true), 'attachment.max_size_mb' => $this->limit($premium ? 20 : 5), 'attachment.max_per_message' => $this->limit($premium ? 10 : 3),
            // Existing implementation keys deliberately remain canonical.
            'send_email' => $this->enabled($premium), 'reply_email' => $this->enabled($premium), 'forward_email' => $this->enabled($premium), 'outbound.schedule' => $this->enabled($premium), 'outbound.sender_profiles' => $this->enabled($premium),
            'outbound_messages_per_period' => $this->periodLimit($premium ? 1000 : 0), 'outbound_recipients_per_period' => $this->periodLimit($premium ? 2500 : 0), 'outbound_attachment_bytes_per_period' => $this->periodLimit($premium ? 104857600 : 0), 'outbound_retention_days' => ['days' => $premium ? 30 : 1],
            'api.read' => $this->enabled(true), 'api.write' => $this->enabled($premium), 'api.max_requests_per_minute' => $this->limit($premium ? 120 : 20), 'max_api_keys' => $this->limit($premium ? 10 : 1),
            'webhook.access' => $this->enabled($premium), 'webhook.max_endpoints' => $this->limit($premium ? 10 : 0), 'ads.visible' => $this->enabled(! $premium), 'analytics.basic' => $this->enabled($premium), 'analytics.advanced' => $this->enabled(false), 'priority.processing' => $this->enabled($premium), 'support.priority' => $this->enabled($premium),
            'mail_server_pools' => ['pools' => $premium ? ['public', 'standard', 'premium'] : ['public', 'standard']],
        ];

        foreach ($values as $key => $value) {
            $feature = $features[$key] ?? Feature::query()->where('key', $key)->firstOrFail();
            $plan->features()->syncWithoutDetaching([$feature->id => ['feature_value' => $value]]);
        }
    }

    /** @return array{enabled: bool} */
    private function enabled(bool $value): array
    {
        return ['enabled' => $value];
    }

    /** @return array{limit: int} */
    private function limit(int $value): array
    {
        return ['limit' => $value];
    }

    /** @return array{limit: int, reset_period: string} */
    private function periodLimit(int $value): array
    {
        return ['limit' => $value, 'reset_period' => 'monthly'];
    }
}
