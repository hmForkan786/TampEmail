<?php

declare(strict_types=1);

namespace App\Jobs\Affiliates;

use App\Services\Affiliates\AffiliateConversionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class RecordAffiliateConversionJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    /** @return list<int> */
    public function backoff(): array
    {
        return [15, 60, 300, 900, 1800];
    }

    public function __construct(public readonly string $billingOrderId) {}

    public function uniqueId(): string
    {
        return 'affiliate-convert:'.$this->billingOrderId;
    }

    public function handle(AffiliateConversionService $conversions): void
    {
        $conversions->recordFromPaidOrder($this->billingOrderId);
    }
}
