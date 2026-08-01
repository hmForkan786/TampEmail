<?php

declare(strict_types=1);

namespace App\Services\Ads;

use App\DTOs\Ads\AdAudienceContext;
use App\Enums\AdCampaignStatus;
use App\Events\Ads\AdClicked;
use App\Events\Ads\AdRendered;
use App\Events\Ads\CampaignBudgetReached;
use App\Models\AdCampaign;
use App\Models\AdClick;
use App\Models\AdImpression;
use App\Models\AdPlacement;
use Illuminate\Support\Facades\DB;

final class AdStatisticsService
{
    public function recordImpression(
        AdCampaign $campaign,
        AdPlacement $placement,
        AdAudienceContext $context,
    ): AdImpression {
        return DB::transaction(function () use ($campaign, $placement, $context): AdImpression {
            $this->resetDailyCountersIfNeeded($campaign);

            $impression = AdImpression::query()->create([
                'ad_campaign_id' => $campaign->getKey(),
                'ad_placement_id' => $placement->getKey(),
                'user_id' => $context->user?->getKey(),
                'session_hash' => $context->sessionHash,
                'country' => $context->country,
                'device' => $context->device?->value,
                'language' => $context->language,
                'ip_hash' => $context->ipHash,
            ]);

            $campaign->impressions_today++;
            $campaign->impressions_total++;
            $campaign->save();

            event(new AdRendered($campaign, $placement, $impression));

            if ($this->budgetExhausted($campaign)) {
                $campaign->status = AdCampaignStatus::BudgetReached;
                $campaign->save();
                event(new CampaignBudgetReached($campaign));
            }

            return $impression;
        });
    }

    public function recordClick(
        AdCampaign $campaign,
        AdPlacement $placement,
        AdAudienceContext $context,
        ?AdImpression $impression = null,
        ?string $destinationUrl = null,
    ): AdClick {
        return DB::transaction(function () use ($campaign, $placement, $context, $impression, $destinationUrl): AdClick {
            $this->resetDailyCountersIfNeeded($campaign);

            $click = AdClick::query()->create([
                'ad_campaign_id' => $campaign->getKey(),
                'ad_placement_id' => $placement->getKey(),
                'ad_impression_id' => $impression?->getKey(),
                'user_id' => $context->user?->getKey(),
                'session_hash' => $context->sessionHash,
                'country' => $context->country,
                'device' => $context->device?->value,
                'language' => $context->language,
                'ip_hash' => $context->ipHash,
                'destination_url' => $destinationUrl,
            ]);

            $campaign->clicks_today++;
            $campaign->clicks_total++;
            $campaign->save();

            event(new AdClicked($campaign, $placement, $click));

            if ($this->budgetExhausted($campaign)) {
                $campaign->status = AdCampaignStatus::BudgetReached;
                $campaign->save();
                event(new CampaignBudgetReached($campaign));
            }

            return $click;
        });
    }

    /**
     * @return array{impressions: int, clicks: int, ctr: float, revenue_minor: int}
     */
    public function summary(?AdCampaign $campaign = null): array
    {
        $impressionsQuery = AdImpression::query();
        $clicksQuery = AdClick::query();
        $revenueQuery = \App\Models\AdRevenueEntry::query();

        if ($campaign !== null) {
            $impressionsQuery->where('ad_campaign_id', $campaign->getKey());
            $clicksQuery->where('ad_campaign_id', $campaign->getKey());
            $revenueQuery->where('ad_campaign_id', $campaign->getKey());
        }

        $impressions = (int) $impressionsQuery->count();
        $clicks = (int) $clicksQuery->count();
        $ctr = $impressions > 0 ? round(($clicks / $impressions) * 100, 4) : 0.0;
        $revenue = (int) $revenueQuery->sum('amount_minor');

        return [
            'impressions' => $impressions,
            'clicks' => $clicks,
            'ctr' => $ctr,
            'revenue_minor' => $revenue,
        ];
    }

    public function prune(int $impressionDays, int $clickDays): array
    {
        $impressionsDeleted = AdImpression::query()
            ->where('created_at', '<', now()->subDays(max(1, $impressionDays)))
            ->delete();

        $clicksDeleted = AdClick::query()
            ->where('created_at', '<', now()->subDays(max(1, $clickDays)))
            ->delete();

        return [
            'impressions_deleted' => $impressionsDeleted,
            'clicks_deleted' => $clicksDeleted,
        ];
    }

    private function resetDailyCountersIfNeeded(AdCampaign $campaign): void
    {
        $today = now()->toDateString();
        $budgetDay = $campaign->budget_day?->toDateString();

        if ($budgetDay === $today) {
            return;
        }

        $campaign->impressions_today = 0;
        $campaign->clicks_today = 0;
        $campaign->budget_day = $today;
        $campaign->save();
    }

    private function budgetExhausted(AdCampaign $campaign): bool
    {
        if ($campaign->max_impressions !== null && $campaign->impressions_total >= $campaign->max_impressions) {
            return true;
        }

        if ($campaign->max_clicks !== null && $campaign->clicks_total >= $campaign->max_clicks) {
            return true;
        }

        if ($campaign->daily_budget !== null && $campaign->impressions_today >= $campaign->daily_budget) {
            return true;
        }

        return false;
    }
}
