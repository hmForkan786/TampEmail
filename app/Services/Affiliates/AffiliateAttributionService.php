<?php

declare(strict_types=1);

namespace App\Services\Affiliates;

use App\Enums\AffiliateAttributionModel;
use App\Enums\AffiliateAttributionStatus;
use App\Models\AffiliateAttribution;
use App\Models\AffiliateCommissionPlan;
use App\Models\AffiliateProfile;
use App\Models\User;
use App\Services\Audit\AuditLogWriter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Records referral clicks, resolves opaque visitor tokens, and links
 * attribution to newly registered/converting users.
 */
final class AffiliateAttributionService
{
    private const VISITOR_TOKEN_PATTERN = '/^[a-f0-9]{64}$/i';

    public function __construct(
        private readonly AffiliateHashingService $hashing,
        private readonly AuditLogWriter $audit,
    ) {}

    /**
     * @return array{attribution: ?AffiliateAttribution, visitor_token: ?string, cookie_should_set: bool}
     */
    public function recordClick(string $affiliateCode, Request $request, ?string $existingVisitorToken = null): array
    {
        $none = ['attribution' => null, 'visitor_token' => null, 'cookie_should_set' => false];

        if (config('affiliates.enabled') !== true) {
            return $none;
        }

        $code = Str::upper(trim($affiliateCode));

        if ($code === '' || preg_match('/^[A-Z0-9]{4,32}$/', $code) !== 1) {
            return $none;
        }

        $affiliate = AffiliateProfile::query()->where('affiliate_code', $code)->first();

        if (! $affiliate instanceof AffiliateProfile || ! $affiliate->canReceiveAttribution()) {
            return $none;
        }

        $visitorToken = $this->resolveVisitorTokenFromCookie($existingVisitorToken) ?? $this->generateVisitorToken();
        $tokenHash = $this->hashing->hashVisitorToken($visitorToken);

        $plan = $affiliate->plan;
        $cookieDays = $plan instanceof AffiliateCommissionPlan
            ? $plan->cookie_window_days
            : (int) config('affiliates.cookie.days', 30);
        $model = AffiliateAttributionModel::tryFrom((string) config('affiliates.attribution_model'))
            ?? AffiliateAttributionModel::LastClick;

        $landingUrl = $this->sanitizeUrl($request->fullUrl());
        $referrerUrl = $this->sanitizeUrl($request->headers->get('referer'));
        $utmSource = $this->sanitizeUtm($request->query('utm_source'));
        $utmMedium = $this->sanitizeUtm($request->query('utm_medium'));
        $utmCampaign = $this->sanitizeUtm($request->query('utm_campaign'));
        $ipHash = $this->hashing->hashIp($request->ip());
        $userAgentHash = $this->hashing->hashUserAgent($request->userAgent());

        $attribution = DB::transaction(function () use (
            $affiliate, $code, $tokenHash, $model, $cookieDays,
            $landingUrl, $referrerUrl, $utmSource, $utmMedium, $utmCampaign, $ipHash, $userAgentHash,
        ): AffiliateAttribution {
            $existingActive = AffiliateAttribution::query()
                ->where('visitor_token_hash', $tokenHash)
                ->where('status', AffiliateAttributionStatus::Active->value)
                ->where('expires_at', '>', now())
                ->orderByDesc('last_seen_at')
                ->lockForUpdate()
                ->first();

            if ($existingActive instanceof AffiliateAttribution
                && ($model === AffiliateAttributionModel::FirstClick || $existingActive->affiliate_profile_id === $affiliate->getKey())
            ) {
                $existingActive->forceFill([
                    'last_seen_at' => now(),
                    'expires_at' => $existingActive->affiliate_profile_id === $affiliate->getKey()
                        ? now()->addDays($cookieDays)
                        : $existingActive->expires_at,
                ])->save();

                return $existingActive;
            }

            if ($existingActive instanceof AffiliateAttribution) {
                // After the first-click / same-affiliate early return above, any
                // remaining active attribution under last-click (or an unexpected
                // model) must be invalidated before creating the replacement.
                $existingActive->forceFill(['status' => AffiliateAttributionStatus::Invalidated])->save();
            }

            return AffiliateAttribution::query()->create([
                'affiliate_profile_id' => $affiliate->getKey(),
                'visitor_token_hash' => $tokenHash,
                'referral_code' => $code,
                'landing_url' => $landingUrl,
                'referrer_url' => $referrerUrl,
                'utm_source' => $utmSource,
                'utm_medium' => $utmMedium,
                'utm_campaign' => $utmCampaign,
                'ip_hash' => $ipHash,
                'user_agent_hash' => $userAgentHash,
                'first_seen_at' => now(),
                'last_seen_at' => now(),
                'expires_at' => now()->addDays($cookieDays),
                'status' => AffiliateAttributionStatus::Active,
            ]);
        });

        $this->audit->write(
            $attribution->wasRecentlyCreated ? 'affiliate.attribution_created' : 'affiliate.click_recorded',
            null,
            $attribution,
            null,
            ['affiliate_profile_id' => $affiliate->getKey(), 'referral_code' => $code],
        );

        return [
            'attribution' => $attribution,
            'visitor_token' => $visitorToken,
            'cookie_should_set' => true,
        ];
    }

    public function resolveVisitorTokenFromCookie(?string $cookieValue): ?string
    {
        if ($cookieValue === null) {
            return null;
        }

        $trimmed = trim($cookieValue);

        if (preg_match(self::VISITOR_TOKEN_PATTERN, $trimmed) !== 1) {
            return null;
        }

        return strtolower($trimmed);
    }

    public function linkUser(User $user, ?string $visitorToken): ?AffiliateAttribution
    {
        if (config('affiliates.enabled') !== true) {
            return null;
        }

        $token = $this->resolveVisitorTokenFromCookie($visitorToken);

        if ($token === null) {
            return null;
        }

        $alreadyConverted = AffiliateAttribution::query()
            ->where('converted_user_id', $user->getKey())
            ->where('status', AffiliateAttributionStatus::Converted->value)
            ->exists();

        if ($alreadyConverted) {
            return null;
        }

        $tokenHash = $this->hashing->hashVisitorToken($token);

        return DB::transaction(function () use ($user, $tokenHash): ?AffiliateAttribution {
            $attribution = AffiliateAttribution::query()
                ->where('visitor_token_hash', $tokenHash)
                ->where('status', AffiliateAttributionStatus::Active->value)
                ->where('expires_at', '>', now())
                ->orderByDesc('last_seen_at')
                ->lockForUpdate()
                ->first();

            if (! $attribution instanceof AffiliateAttribution) {
                return null;
            }

            $affiliate = AffiliateProfile::query()->whereKey($attribution->affiliate_profile_id)->lockForUpdate()->first();

            if (! $affiliate instanceof AffiliateProfile) {
                return null;
            }

            if ($affiliate->user_id === $user->getKey()) {
                $attribution->forceFill(['status' => AffiliateAttributionStatus::Invalidated])->save();
                $this->audit->write('affiliate.attribution_invalidated', $user->getKey(), $attribution, null, ['reason' => 'self_referral']);

                return null;
            }

            $attribution->forceFill([
                'converted_user_id' => $user->getKey(),
                'converted_at' => now(),
                'status' => AffiliateAttributionStatus::Converted,
            ])->save();

            $this->audit->write('affiliate.attribution_converted', $user->getKey(), $attribution, null, [
                'affiliate_profile_id' => $affiliate->getKey(),
            ]);

            return $attribution;
        });
    }

    public function expireDue(int $limit): int
    {
        $ids = AffiliateAttribution::query()
            ->where('status', AffiliateAttributionStatus::Active->value)
            ->where('expires_at', '<=', now())
            ->limit($limit)
            ->pluck('id');

        if ($ids->isEmpty()) {
            return 0;
        }

        return AffiliateAttribution::query()
            ->whereIn('id', $ids)
            ->update(['status' => AffiliateAttributionStatus::Expired->value, 'updated_at' => now()]);
    }

    /** Never deletes converted attributions that have associated conversions. */
    public function pruneExpired(int $limit, bool $dryRun): int
    {
        $cutoff = now()->subDays((int) config('affiliates.attribution_retention_days', 90));

        $query = AffiliateAttribution::query()
            ->whereIn('status', [AffiliateAttributionStatus::Expired->value, AffiliateAttributionStatus::Invalidated->value])
            ->where('updated_at', '<', $cutoff)
            ->whereDoesntHave('conversions')
            ->limit($limit);

        if ($dryRun) {
            return $query->count();
        }

        $ids = $query->pluck('id');

        if ($ids->isEmpty()) {
            return 0;
        }

        return AffiliateAttribution::query()->whereIn('id', $ids)->delete();
    }

    private function generateVisitorToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    private function sanitizeUrl(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        $trimmed = trim($url);

        if ($trimmed === '' || stripos($trimmed, 'javascript:') === 0) {
            return null;
        }

        return Str::limit($trimmed, 2048, '');
    }

    private function sanitizeUtm(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        if ($trimmed === '') {
            return null;
        }

        $filtered = preg_replace('/[^A-Za-z0-9_\-.]/', '', $trimmed) ?? '';

        return $filtered === '' ? null : Str::limit($filtered, 100, '');
    }
}
