<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Enums\ApiKeyScope;
use App\Exceptions\Affiliates\AffiliateException;
use App\Http\Responses\ApiErrorResponse;
use App\Models\AffiliateProfile;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Shared helpers for affiliate-facing API controllers: resolving the
 * authenticated user from the API key and loading their own affiliate
 * profile. No new {@see ApiKeyScope} values are introduced;
 * every affiliate endpoint relies solely on `api.key` authentication plus
 * per-route throttling, with owner isolation enforced by scoping every
 * query to the caller's own affiliate profile.
 */
trait InteractsWithAffiliateProfile
{
    protected function affiliateUser(Request $request): User
    {
        $user = $request->attributes->get('apiKey')?->user;

        if (! $user instanceof User) {
            abort(401);
        }

        return $user;
    }

    protected function requireProfile(User $user): AffiliateProfile
    {
        $profile = AffiliateProfile::query()->where('user_id', $user->getKey())->first();

        if (! $profile instanceof AffiliateProfile) {
            abort(ApiErrorResponse::make('affiliate_profile_not_found', 'No affiliate profile exists for this account.', 404));
        }

        return $profile;
    }

    protected function maskEmail(?string $email): ?string
    {
        if ($email === null || ! str_contains($email, '@')) {
            return null;
        }

        [$local, $domain] = explode('@', $email, 2);
        $visible = mb_substr($local, 0, 1);

        return $visible.str_repeat('*', max(1, mb_strlen($local) - 1)).'@'.$domain;
    }

    /**
     * Maps a domain-level affiliate exception (registration/withdrawal/
     * eligibility) to a stable, machine-readable 422 error envelope.
     */
    protected function affiliateErrorResponse(AffiliateException $exception): JsonResponse
    {
        $short = class_basename($exception);
        $short = str_ends_with($short, 'Exception') ? substr($short, 0, -strlen('Exception')) : $short;

        return ApiErrorResponse::make('affiliate_'.Str::snake($short), $exception->getMessage(), 422);
    }
}
