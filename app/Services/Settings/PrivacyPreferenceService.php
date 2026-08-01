<?php

declare(strict_types=1);

namespace App\Services\Settings;

use App\Enums\PrivacyExportStatus;
use App\Jobs\Settings\ProcessPrivacyExportJob;
use App\Models\AffiliateProfile;
use App\Models\ApiKey;
use App\Models\User;
use App\Models\UserPrivacyExport;
use App\Services\Audit\AuditLogWriter;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Bounded privacy center and personal-data export foundation.
 */
final class PrivacyPreferenceService
{
    public function __construct(
        private readonly AuditLogWriter $audit,
        private readonly SettingsAnalyticsRecorder $analytics,
        private readonly NotificationPreferenceService $notifications,
        private readonly BillingPreferenceService $billingPreferences,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function centerSummary(User $user): array
    {
        $identity = $user->identityPreference;
        $export = UserPrivacyExport::query()
            ->where('user_id', $user->getKey())
            ->latest('requested_at')
            ->first();

        $identity = $user->identityPreference;

        return [
            'data_collected_summary' => [
                'profile' => true,
                'preferences' => true,
                'login_history_metadata' => true,
                'api_key_metadata' => true,
                'billing_history' => true,
                'affiliate_records' => AffiliateProfile::query()->where('user_id', $user->getKey())->exists(),
            ],
            'marketing_consent' => $identity !== null ? (bool) $identity->marketing_consent : false,
            'marketing_consent_at' => $identity?->marketing_consent_at?->toIso8601String(),
            'marketing_policy_version' => $identity?->marketing_policy_version,
            'login_history_retention_days' => (int) config('settings.privacy.login_history_retention_days', 90),
            'privacy_policy_url' => (string) config('settings.privacy.policy_url', '/privacy'),
            'privacy_policy_version' => (string) config('settings.privacy.policy_version', '2026-08-01'),
            'cookie_preferences_documented' => (bool) config('settings.privacy.cookie_preferences_documented', true),
            'export_enabled' => (bool) config('settings.privacy.export.enabled', true),
            'included_datasets' => (array) config('settings.privacy.export.include_datasets', []),
            'deferred_datasets' => (array) config('settings.privacy.export.deferred_datasets', []),
            'latest_export' => $export ? [
                'id' => $export->getKey(),
                'status' => $export->status->value,
                'requested_at' => $export->requested_at?->toIso8601String(),
                'ready_at' => $export->ready_at?->toIso8601String(),
                'expires_at' => $export->expires_at?->toIso8601String(),
                'downloadable' => $export->isDownloadable(),
            ] : null,
            'compliance_claim' => 'Foundation only — not a GDPR/compliance certification.',
        ];
    }

    public function requestExport(User $user, bool $passwordConfirmed): UserPrivacyExport
    {
        if (! $passwordConfirmed) {
            throw ValidationException::withMessages([
                'password' => __('Please confirm your password.'),
            ]);
        }

        if (config('settings.privacy.export.enabled') !== true) {
            throw ValidationException::withMessages([
                'export' => __('Privacy export is currently disabled.'),
            ]);
        }

        $rateHours = max(1, (int) config('settings.privacy.export.rate_limit_hours', 24));
        $recent = UserPrivacyExport::query()
            ->where('user_id', $user->getKey())
            ->where('requested_at', '>=', now()->subHours($rateHours))
            ->whereIn('status', [
                PrivacyExportStatus::Pending->value,
                PrivacyExportStatus::Processing->value,
                PrivacyExportStatus::Ready->value,
            ])
            ->exists();

        if ($recent) {
            throw ValidationException::withMessages([
                'export' => __('An export was already requested recently. Please wait before requesting another.'),
            ]);
        }

        $export = DB::transaction(function () use ($user): UserPrivacyExport {
            $row = UserPrivacyExport::query()->create([
                'user_id' => $user->getKey(),
                'status' => PrivacyExportStatus::Pending,
                'included_datasets' => (array) config('settings.privacy.export.include_datasets', []),
                'deferred_datasets' => (array) config('settings.privacy.export.deferred_datasets', []),
                'requested_at' => now(),
                'expires_at' => now()->addHours((int) config('settings.privacy.export.ttl_hours', 48)),
            ]);

            $this->audit->write('settings.privacy_export_requested', (string) $user->getKey(), $row, metadata: [
                'included' => $row->included_datasets,
                'deferred' => $row->deferred_datasets,
            ]);
            $this->analytics->record('settings.export_requested', (string) $user->getKey());

            return $row;
        });

        ProcessPrivacyExportJob::dispatch((string) $export->getKey());

        return $export;
    }

    public function download(User $user, UserPrivacyExport $export): StreamedResponse
    {
        if ((string) $export->user_id !== (string) $user->getKey()) {
            abort(404);
        }

        if (! $export->isDownloadable()) {
            throw ValidationException::withMessages([
                'export' => __('This export is not available for download.'),
            ]);
        }

        $disk = (string) ($export->disk ?: config('settings.privacy.export.disk', 'local'));
        $path = (string) $export->path;

        if (! Storage::disk($disk)->exists($path)) {
            throw ValidationException::withMessages([
                'export' => __('Export archive is missing.'),
            ]);
        }

        $export->forceFill([
            'status' => PrivacyExportStatus::Downloaded,
            'downloaded_at' => now(),
        ])->save();

        $this->audit->write('settings.privacy_export_downloaded', (string) $user->getKey(), $export);

        return Storage::disk($disk)->download($path, 'temail-privacy-export-'.$export->getKey().'.json');
    }

    /**
     * Build the bounded JSON archive (no secrets).
     *
     * @return array<string, mixed>
     */
    public function buildArchivePayload(User $user): array
    {
        $this->notifications->ensureDefaults($user);

        /** @var Collection<int, ApiKey> $apiKeys */
        $apiKeys = $user->apiKeys()->get(['id', 'name', 'key_prefix', 'permissions', 'last_used_at', 'expires_at', 'revoked_at', 'created_at']);

        return [
            'generated_at' => now()->toIso8601String(),
            'user_id' => $user->getKey(),
            'profile' => [
                'name' => $user->name,
                'email' => $user->email,
                'locale' => $user->locale,
                'timezone' => $user->timezone,
                'status' => $user->status->value,
                'email_verified_at' => $user->email_verified_at?->toIso8601String(),
                'created_at' => $user->created_at?->toIso8601String(),
            ],
            'notification_preferences' => $this->notifications->listForUser($user),
            'billing_preferences' => $this->billingPreferences->summary($user),
            'api_keys' => $apiKeys
                ->map(static function (ApiKey $key): array {
                    return [
                        'id' => $key->getKey(),
                        'name' => $key->name,
                        'prefix' => $key->key_prefix,
                        'scopes' => $key->permissions,
                        'last_used_at' => $key->last_used_at?->toIso8601String(),
                        'expires_at' => $key->expires_at?->toIso8601String(),
                        'revoked_at' => $key->revoked_at?->toIso8601String(),
                        'created_at' => $key->created_at?->toIso8601String(),
                    ];
                })->all(),
            'deferred_datasets' => (array) config('settings.privacy.export.deferred_datasets', []),
            'notice' => 'This export is a bounded foundation and intentionally excludes secrets and deferred datasets.',
        ];
    }
}
