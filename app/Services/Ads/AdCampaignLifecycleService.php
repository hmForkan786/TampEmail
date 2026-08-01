<?php

declare(strict_types=1);

namespace App\Services\Ads;

use App\Enums\AdCampaignStatus;
use App\Events\Ads\CampaignDisabled;
use App\Events\Ads\CampaignEnabled;
use App\Events\Ads\CampaignExpired;
use App\Models\AdCampaign;
use App\Models\User;
use App\Services\Audit\AuditLogWriter;
use Illuminate\Support\Facades\DB;

final class AdCampaignLifecycleService
{
    public function __construct(private readonly AuditLogWriter $audit) {}

    public function enable(AdCampaign $campaign, ?User $actor = null): AdCampaign
    {
        $campaign->status = AdCampaignStatus::Active;
        $campaign->save();

        event(new CampaignEnabled($campaign));
        $this->audit->write(
            action: 'ads.campaign.enabled',
            actorUserId: $actor?->getKey(),
            auditable: $campaign,
            newValues: ['status' => AdCampaignStatus::Active->value],
        );

        return $campaign;
    }

    public function disable(AdCampaign $campaign, ?User $actor = null): AdCampaign
    {
        $campaign->status = AdCampaignStatus::Paused;
        $campaign->save();

        event(new CampaignDisabled($campaign));
        $this->audit->write(
            action: 'ads.campaign.disabled',
            actorUserId: $actor?->getKey(),
            auditable: $campaign,
            newValues: ['status' => AdCampaignStatus::Paused->value],
        );

        return $campaign;
    }

    /** @return int Number of campaigns marked expired */
    public function expireDueCampaigns(): int
    {
        $count = 0;
        $due = AdCampaign::query()
            ->where('status', AdCampaignStatus::Active->value)
            ->whereNotNull('ends_at')
            ->where('ends_at', '<', now())
            ->get();

        foreach ($due as $campaign) {
            DB::transaction(function () use ($campaign, &$count): void {
                $campaign->status = AdCampaignStatus::Expired;
                $campaign->save();
                event(new CampaignExpired($campaign));
                $this->audit->write(
                    action: 'ads.campaign.expired',
                    auditable: $campaign,
                    newValues: ['status' => AdCampaignStatus::Expired->value],
                );
                $count++;
            });
        }

        return $count;
    }

    /** Reset daily counters for active campaigns whose budget_day is stale. */
    public function refreshDailyBudgets(): int
    {
        $today = now()->toDateString();

        return AdCampaign::query()
            ->where(function ($query) use ($today): void {
                $query->whereNull('budget_day')->orWhereDate('budget_day', '<', $today);
            })
            ->update([
                'impressions_today' => 0,
                'clicks_today' => 0,
                'budget_day' => $today,
            ]);
    }
}
