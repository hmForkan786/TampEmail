<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\AffiliatePayoutMethod;
use App\Exceptions\Affiliates\AffiliateException;
use App\Http\Controllers\Api\V1\Concerns\InteractsWithAffiliateProfile;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiErrorResponse;
use App\Models\AffiliateWithdrawal;
use App\Services\Affiliates\AffiliateWithdrawalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

final class AffiliateWithdrawalController extends Controller
{
    use InteractsWithAffiliateProfile;

    public function __construct(private readonly AffiliateWithdrawalService $withdrawals) {}

    public function index(Request $request): JsonResponse
    {
        $profile = $this->requireProfile($this->affiliateUser($request));

        $paginator = AffiliateWithdrawal::query()
            ->where('affiliate_profile_id', $profile->getKey())
            ->latest('requested_at')
            ->latest('id')
            ->cursorPaginate(min(100, max(1, (int) $request->integer('per_page', 25))));

        return response()->json([
            'data' => collect($paginator->items())->map(fn (AffiliateWithdrawal $withdrawal): array => $this->project($withdrawal))->values(),
            'per_page' => $paginator->perPage(),
            'next_cursor' => $paginator->nextCursor()?->encode(),
            'prev_cursor' => $paginator->previousCursor()?->encode(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $profile = $this->requireProfile($this->affiliateUser($request));

        $validated = Validator::make($request->all(), [
            'amount_minor' => ['required', 'integer', 'min:1'],
            'currency' => ['required', 'string', 'size:3'],
            'payout_method' => ['required', 'string', 'in:'.implode(',', array_map(fn (AffiliatePayoutMethod $m): string => $m->value, AffiliatePayoutMethod::cases()))],
            'payout_details' => ['required', 'string', 'max:2000'],
            'idempotency_key' => ['nullable', 'string', 'max:120'],
        ])->validate();

        $idempotencyKey = $this->resolveIdempotencyKey($request, $validated);

        try {
            $withdrawal = $this->withdrawals->request(
                $profile,
                (int) $validated['amount_minor'],
                (string) $validated['currency'],
                (string) $validated['payout_method'],
                (string) $validated['payout_details'],
                $idempotencyKey,
            );
        } catch (AffiliateException $exception) {
            return $this->affiliateErrorResponse($exception);
        }

        return response()->json(['data' => $this->project($withdrawal)], $withdrawal->wasRecentlyCreated ? 201 : 200);
    }

    public function cancel(Request $request, string $withdrawal): JsonResponse
    {
        $user = $this->affiliateUser($request);
        $profile = $this->requireProfile($user);

        $model = AffiliateWithdrawal::query()
            ->where('affiliate_profile_id', $profile->getKey())
            ->where('id', $withdrawal)
            ->first();

        if (! $model instanceof AffiliateWithdrawal) {
            return ApiErrorResponse::make('affiliate_withdrawal_not_found', 'Withdrawal not found.', 404);
        }

        try {
            $cancelled = $this->withdrawals->cancel($model, $user);
        } catch (AffiliateException $exception) {
            return $this->affiliateErrorResponse($exception);
        }

        return response()->json(['data' => $this->project($cancelled)]);
    }

    /** @param array<string, mixed> $validated */
    private function resolveIdempotencyKey(Request $request, array $validated): string
    {
        $header = $request->header('Idempotency-Key');

        if (is_string($header) && trim($header) !== '') {
            return Str::limit(trim($header), 120, '');
        }

        if (isset($validated['idempotency_key']) && trim((string) $validated['idempotency_key']) !== '') {
            return Str::limit(trim((string) $validated['idempotency_key']), 120, '');
        }

        return (string) Str::uuid();
    }

    /** @return array<string, mixed> */
    private function project(AffiliateWithdrawal $withdrawal): array
    {
        return [
            'id' => $withdrawal->getKey(),
            'amount_minor' => $withdrawal->amount_minor,
            'currency' => $withdrawal->currency,
            'status' => $withdrawal->status->value,
            'payout_method' => $withdrawal->payout_method->value,
            'requested_at' => $withdrawal->requested_at->toIso8601String(),
            'reviewed_at' => $withdrawal->reviewed_at?->toIso8601String(),
            'approved_at' => $withdrawal->approved_at?->toIso8601String(),
            'paid_at' => $withdrawal->paid_at?->toIso8601String(),
            'external_reference' => $withdrawal->external_reference,
            'rejection_reason' => $withdrawal->rejection_reason,
        ];
    }
}
