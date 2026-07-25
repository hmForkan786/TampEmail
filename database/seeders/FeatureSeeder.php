<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\ValueType;
use App\Models\Feature;
use App\Services\Outbound\OutboundUsageService;
use Illuminate\Database\Seeder;

/**
 * Registers the platform feature catalog via `updateOrCreate` keyed by the
 * stable `key` column. Safe to run repeatedly and safe on an existing
 * database: it never attaches features to any plan (no `feature_plan`
 * rows are touched here), so no plan silently gains a new entitlement or
 * usage limit just because this seeder ran.
 *
 * A plan without one of the metered features below is treated as
 * UNLIMITED for that dimension by {@see OutboundUsageService}
 * — see docs/OUTBOUND_USAGE_ACCOUNTING.md. Attach features (and any
 * per-plan `feature_value` limit) explicitly and deliberately via a
 * dedicated plan-provisioning step, never automatically from this seeder.
 */
class FeatureSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->features() as $definition) {
            Feature::query()->updateOrCreate(
                ['key' => $definition['key']],
                $definition,
            );
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function features(): array
    {
        return [
            [
                'key' => 'send_email',
                'name' => 'Send Email',
                'description' => 'Allows composing and sending new outbound email from an owned inbox.',
                'category' => 'outbound',
                'value_type' => ValueType::Boolean->value,
                'default_value' => ['enabled' => false],
                'is_active' => true,
                'display_order' => 10,
            ],
            [
                'key' => 'reply_email',
                'name' => 'Reply to Email',
                'description' => 'Allows replying to inbound email from an owned inbox.',
                'category' => 'outbound',
                'value_type' => ValueType::Boolean->value,
                'default_value' => ['enabled' => false],
                'is_active' => true,
                'display_order' => 11,
            ],
            [
                'key' => 'forward_email',
                'name' => 'Forward Email',
                'description' => 'Allows forwarding inbound email (with clean attachments) from an owned inbox.',
                'category' => 'outbound',
                'value_type' => ValueType::Boolean->value,
                'default_value' => ['enabled' => false],
                'is_active' => true,
                'display_order' => 12,
            ],
            [
                'key' => 'outbound_retention_days',
                'name' => 'Outbound Content Retention (days)',
                'description' => 'Number of days outbound message content is retained before redaction.',
                'category' => 'outbound',
                'value_type' => ValueType::Json->value,
                'default_value' => ['days' => null],
                'is_active' => true,
                'display_order' => 20,
            ],
            [
                'key' => 'outbound_messages_per_period',
                'name' => 'Outbound Messages per Period',
                'description' => 'Metered outbound message allowance per reset period. Missing from a plan means unlimited.',
                'category' => 'outbound_usage',
                'value_type' => ValueType::Json->value,
                'default_value' => ['limit' => null, 'reset_period' => 'monthly'],
                'is_active' => true,
                'display_order' => 30,
            ],
            [
                'key' => 'outbound_recipients_per_period',
                'name' => 'Outbound Recipients per Period',
                'description' => 'Metered outbound recipient allowance per reset period. Missing from a plan means unlimited.',
                'category' => 'outbound_usage',
                'value_type' => ValueType::Json->value,
                'default_value' => ['limit' => null, 'reset_period' => 'monthly'],
                'is_active' => true,
                'display_order' => 31,
            ],
            [
                'key' => 'outbound_attachment_bytes_per_period',
                'name' => 'Outbound Attachment Bytes per Period',
                'description' => 'Metered outbound forward attachment byte allowance per reset period. Missing from a plan means unlimited.',
                'category' => 'outbound_usage',
                'value_type' => ValueType::Json->value,
                'default_value' => ['limit' => null, 'reset_period' => 'monthly'],
                'is_active' => true,
                'display_order' => 32,
            ],
        ];
    }
}
