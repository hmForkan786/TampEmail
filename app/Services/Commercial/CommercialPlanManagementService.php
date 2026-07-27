<?php

declare(strict_types=1);

namespace App\Services\Commercial;

use App\Enums\ValueType;
use App\Exceptions\CommercialManagementException;
use App\Models\Feature;
use App\Models\Pivots\FeaturePlan;
use App\Models\Plan;
use App\Models\User;
use App\Services\Audit\AuditLogWriter;
use Illuminate\Support\Facades\DB;

final class CommercialPlanManagementService
{
    private const CANONICAL = ['free', 'premium'];

    private const FREE_BOOLEAN_INVARIANTS = ['inbox.create' => true, 'ads.visible' => true, 'send_email' => false, 'reply_email' => false, 'forward_email' => false, 'api.write' => false, 'webhook.access' => false];

    public function __construct(private readonly AuditLogWriter $audit) {}

    /** @param array<string, mixed> $attributes */
    public function updatePlan(User $actor, Plan $plan, array $attributes, string $expectedUpdatedAt, string $reason): Plan
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($actor, $plan, $attributes, $expectedUpdatedAt, $reason): Plan {
            $locked = Plan::withTrashed()->whereKey($plan->getKey())->lockForUpdate()->firstOrFail();
            $this->assertFresh($locked, $expectedUpdatedAt);
            if (in_array($locked->slug, self::CANONICAL, true) && array_key_exists('slug', $attributes) && $attributes['slug'] !== $locked->slug) {
                throw new CommercialManagementException('Canonical plan keys cannot be changed.');
            }
            if ($locked->slug === 'free' && (($attributes['is_active'] ?? true) !== true || (float) ($attributes['price_monthly'] ?? 0) !== 0.0 || (float) ($attributes['price_yearly'] ?? 0) !== 0.0)) {
                throw new CommercialManagementException('The Free plan must remain active and priced at zero.');
            }
            $before = $locked->only(['name', 'description', 'price_monthly', 'price_yearly', 'currency', 'is_active', 'display_order']);
            $locked->fill($attributes)->save();
            $this->audit->write($locked->is_active ? 'commercial.plan.updated' : 'commercial.plan.deactivated', (string) $actor->getKey(), $locked, $before, $locked->only(array_keys($before)), ['reason' => $reason, 'source' => 'commercial_admin']);

            return $locked->fresh();
        });
    }

    /** @param array<string, mixed> $value */
    public function updateFeatureValue(User $actor, Plan $plan, Feature $feature, array $value, string $expectedUpdatedAt, string $reason): void
    {
        $this->authorize($actor);
        DB::transaction(function () use ($actor, $plan, $feature, $value, $expectedUpdatedAt, $reason): void {
            $locked = Plan::query()->whereKey($plan->getKey())->lockForUpdate()->firstOrFail();
            $this->assertFresh($locked, $expectedUpdatedAt);
            $this->validateValue($feature, $value);
            $this->assertInvariant($locked, $feature, $value);
            $pivot = FeaturePlan::query()->where('plan_id', $locked->getKey())->where('feature_id', $feature->getKey())->lockForUpdate()->first();
            if ($pivot === null) {
                throw new CommercialManagementException('The selected feature is not mapped to this plan.');
            }
            $before = $pivot->feature_value;
            $pivot->forceFill(['feature_value' => $value])->save();
            $locked->touch();
            $this->audit->write('commercial.plan_feature.updated', (string) $actor->getKey(), $locked, ['feature_key' => $feature->key, 'value' => $before], ['feature_key' => $feature->key, 'value' => $value], ['reason' => $reason, 'source' => 'commercial_admin']);
        });
    }

    private function authorize(User $actor): void
    {
        if (! $actor->isPlatformAdmin()) {
            throw new CommercialManagementException('Commercial management requires an active platform admin.');
        }
    }

    private function assertFresh(Plan $plan, string $expected): void
    {
        if ($plan->updated_at?->toIso8601String() !== $expected) {
            throw new CommercialManagementException('This record changed since it was opened. Reload and review the latest values.');
        }
    }

    /** @param array<string, mixed> $value */
    private function validateValue(Feature $feature, array $value): void
    {
        if ($feature->value_type === ValueType::Boolean && ! is_bool($value['enabled'] ?? null)) {
            throw new CommercialManagementException('Boolean features require an enabled true or false value.');
        }
        if (in_array($feature->value_type, [ValueType::Integer, ValueType::Json], true)) {
            $limit = $value['limit'] ?? null;
            if (! is_int($limit) || $limit < 0 || $limit > 1000000) {
                throw new CommercialManagementException('Numeric feature limits must be finite integers between 0 and 1000000.');
            }
        }
        if (! in_array($feature->value_type, [ValueType::Boolean, ValueType::Integer, ValueType::Json], true)) {
            throw new CommercialManagementException('Unsupported feature value type is read-only.');
        }
    }

    /** @param array<string, mixed> $value */
    private function assertInvariant(Plan $plan, Feature $feature, array $value): void
    {
        if ($plan->slug !== 'free') {
            return;
        }
        if (array_key_exists($feature->key, self::FREE_BOOLEAN_INVARIANTS) && ($value['enabled'] ?? null) !== self::FREE_BOOLEAN_INVARIANTS[$feature->key]) {
            throw new CommercialManagementException('This Free-plan entitlement is a protected commercial invariant.');
        }
        if ($feature->key === 'max_inboxes' && (($value['limit'] ?? 0) < 1)) {
            throw new CommercialManagementException('The Free plan must allow at least one active inbox.');
        }
    }
}
