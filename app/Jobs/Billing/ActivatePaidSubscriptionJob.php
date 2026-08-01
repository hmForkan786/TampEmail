<?php

declare(strict_types=1);

namespace App\Jobs\Billing;

use App\Services\Billing\PaidSubscriptionActivationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class ActivatePaidSubscriptionJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    /** @return list<int> */
    public function backoff(): array
    {
        return [15, 60, 300, 900];
    }

    public function __construct(public readonly string $billingOrderId) {}

    public function uniqueId(): string
    {
        return 'billing-activate:'.$this->billingOrderId;
    }

    public function handle(PaidSubscriptionActivationService $activation): void
    {
        $activation->activateFromPaidOrder($this->billingOrderId);
    }
}
