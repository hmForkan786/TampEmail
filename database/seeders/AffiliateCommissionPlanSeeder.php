<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AffiliateCommissionPlanStatus;
use App\Enums\AffiliateCommissionType;
use App\Models\AffiliateCommissionPlan;
use Illuminate\Database\Seeder;

final class AffiliateCommissionPlanSeeder extends Seeder
{
    public function run(): void
    {
        AffiliateCommissionPlan::query()->updateOrCreate(
            ['name' => 'Standard Percentage'],
            [
                'status' => AffiliateCommissionPlanStatus::Active,
                'commission_type' => AffiliateCommissionType::Percentage,
                'percentage_bps' => 1000,
                'fixed_amount_minor' => null,
                'currency' => 'USD',
                'minimum_order_minor' => 0,
                'maximum_commission_minor' => null,
                'cookie_window_days' => 30,
                'commission_hold_days' => 14,
                'new_customer_only' => true,
                'recurring_commission_enabled' => false,
                'recurring_cycles' => null,
            ],
        );
    }
}
