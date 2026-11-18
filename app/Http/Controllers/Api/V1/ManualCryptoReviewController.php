<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\Billing\CheckoutException;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiErrorResponse;
use App\Models\ManualCryptoPaymentClaim;
use App\Models\User;
use App\Services\Billing\BillingResponseFactory;
use App\Services\Billing\ManualCrypto\ManualCryptoReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ManualCryptoReviewController extends Controller
{
    public function __construct(
        private readonly ManualCryptoReviewService $reviews,
        private readonly BillingResponseFactory $responses,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeReviewer($request);
        $claims = ManualCryptoPaymentClaim::query()->with(['snapshot', 'reviewEvents'])
            ->latest('submitted_at')->paginate(min(max((int) $request->integer('per_page', 25), 1), 100));

        return response()->json($claims);
    }

    public function show(Request $request, ManualCryptoPaymentClaim $claim): JsonResponse
    {
        $this->authorizeReviewer($request);

        return response()->json(['data' => $claim->load(['order', 'snapshot', 'reviewEvents'])]);
    }

    public function start(Request $request, ManualCryptoPaymentClaim $claim): JsonResponse
    {
        return $this->mutate($request, $claim, fn (User $user) => $this->reviews->start($claim, $user), false);
    }

    public function approve(Request $request, ManualCryptoPaymentClaim $claim): JsonResponse
    {
        return $this->mutate($request, $claim, fn (User $user, string $reason) => $this->reviews->approve($claim, $user, $reason));
    }

    public function reject(Request $request, ManualCryptoPaymentClaim $claim): JsonResponse
    {
        return $this->mutate($request, $claim, fn (User $user, string $reason) => $this->reviews->reject($claim, $user, $reason));
    }

    public function reopen(Request $request, ManualCryptoPaymentClaim $claim): JsonResponse
    {
        return $this->mutate($request, $claim, fn (User $user, string $reason) => $this->reviews->reopen($claim, $user, $reason));
    }

    private function mutate(Request $request, ManualCryptoPaymentClaim $claim, callable $callback, bool $reasonRequired = true): JsonResponse
    {
        $reviewer = $this->authorizeReviewer($request);
        $reason = $reasonRequired ? (string) $request->validate(['reason' => ['required', 'string', 'max:2000']])['reason'] : '';
        try {
            return response()->json(['data' => $callback($reviewer, $reason)]);
        } catch (CheckoutException $exception) {
            return $this->responses->fromThrowable($exception);
        }
    }

    private function authorizeReviewer(Request $request): User
    {
        $user = $request->attributes->get('apiKey')->user;
        if (! $user instanceof User || ! $user->isPlatformAdmin()) {
            abort(ApiErrorResponse::make('billing_reviewer_required', 'Billing reviewer permission is required.', 403));
        }

        return $user;
    }
}
