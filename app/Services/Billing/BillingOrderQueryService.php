<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\BillingOrderStatus;
use App\Models\BillingCheckoutSession;
use App\Models\BillingOrder;
use Illuminate\Database\Eloquent\Collection;

final class BillingOrderQueryService
{
    public function owned(string $orderId, string $userId): BillingOrder
    {
        return BillingOrder::query()->with('plan')->whereKey($orderId)->where('user_id', $userId)->firstOrFail();
    }

    public function history(string $userId, int $limit = 50): Collection
    {
        return BillingOrder::query()->where('user_id', $userId)->latest()->limit(min(100, max(1, $limit)))->get();
    }

    public function pending(int $limit = 100): Collection
    {
        return BillingOrder::query()->whereIn('status', [BillingOrderStatus::Pending, BillingOrderStatus::Processing])->oldest()->limit($limit)->get();
    }

    public function failedSessions(int $limit = 100): Collection
    {
        return BillingCheckoutSession::query()->where('status', 'failed')->latest()->limit($limit)->get();
    }

    public function expiring(int $limit = 100): Collection
    {
        return BillingOrder::query()->whereIn('status', [BillingOrderStatus::Pending, BillingOrderStatus::Processing])
            ->where('expires_at', '<=', now())->oldest('expires_at')->limit($limit)->get();
    }

    public function byProviderReference(string $provider, string $reference): ?BillingOrder
    {
        return BillingOrder::query()->where('provider', $provider)->where('provider_reference', $reference)->first();
    }
}
