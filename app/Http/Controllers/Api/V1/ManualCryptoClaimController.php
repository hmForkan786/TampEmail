<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\Billing\CheckoutException;
use App\Http\Controllers\Controller;
use App\Models\ManualCryptoPaymentClaim;
use App\Models\ManualCryptoReviewEvent;
use App\Models\User;
use App\Services\Billing\BillingResponseFactory;
use App\Services\Billing\ManualCrypto\ManualCryptoAmount;
use App\Services\Billing\ManualCrypto\ManualCryptoClaimService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ManualCryptoClaimController extends Controller
{
    public function __construct(
        private readonly ManualCryptoClaimService $claims,
        private readonly BillingResponseFactory $responses,
    ) {}

    public function store(Request $request, string $billingOrder): JsonResponse
    {
        $validated = $request->validate([
            'txid' => ['required', 'string', 'max:80'],
            'amount' => ['required', 'string', 'max:40'],
            'screenshot' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:'.(int) config('billing.manual_crypto.max_screenshot_kilobytes', 5120)],
        ]);
        try {
            $claim = $this->claims->submit(
                $billingOrder, (string) $this->user($request)->getKey(),
                (string) $validated['txid'], (string) $validated['amount'],
                $request->file('screenshot'),
            );

            return response()->json(['data' => $this->payload($claim)], 201);
        } catch (CheckoutException $exception) {
            return $this->responses->fromThrowable($exception);
        }
    }

    public function show(Request $request, string $claim): JsonResponse
    {
        $model = ManualCryptoPaymentClaim::query()->with(['snapshot', 'reviewEvents'])
            ->whereKey($claim)->where('user_id', $this->user($request)->getKey())->firstOrFail();

        return response()->json(['data' => $this->payload($model)]);
    }

    /** @return array<string, mixed> */
    private function payload(ManualCryptoPaymentClaim $claim): array
    {
        return [
            'id' => $claim->getKey(), 'order_id' => $claim->billing_order_id,
            'asset' => 'USDT', 'network' => $claim->network,
            'txid' => $claim->txid,
            'submitted_amount' => ManualCryptoAmount::format($claim->submitted_amount_units),
            'state' => $claim->state->value, 'evidence_status' => $claim->evidence_status->value,
            'screenshot_supplied' => $claim->screenshot_path !== null,
            'decision_reason' => $claim->decision_reason,
            'submitted_at' => $claim->submitted_at->toIso8601String(),
            'reviewed_at' => $claim->reviewed_at?->toIso8601String(),
            'history' => $claim->reviewEvents->map(fn (ManualCryptoReviewEvent $event): array => [
                'event' => $event->event, 'from_state' => $event->from_state,
                'to_state' => $event->to_state, 'reason' => $event->reason,
                'created_at' => $event->created_at->toIso8601String(),
            ])->all(),
        ];
    }

    private function user(Request $request): User
    {
        return $request->attributes->get('apiKey')->user;
    }
}
