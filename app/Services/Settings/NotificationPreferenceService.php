<?php

declare(strict_types=1);

namespace App\Services\Settings;

use App\Enums\NotificationPreferenceCategory;
use App\Enums\NotificationPreferenceChannel;
use App\Models\IdentityPreference;
use App\Models\User;
use App\Models\UserNotificationPreference;
use App\Services\Audit\AuditLogWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Typed notification preferences and marketing consent (separate from terms).
 */
final class NotificationPreferenceService
{
    public function __construct(
        private readonly AuditLogWriter $audit,
        private readonly SettingsAnalyticsRecorder $analytics,
    ) {}

    /**
     * Ensure defaults exist for every known category × channel.
     *
     * @return list<UserNotificationPreference>
     */
    public function ensureDefaults(User $user): array
    {
        $rows = [];

        foreach (NotificationPreferenceCategory::cases() as $category) {
            $defaults = (array) config('settings.notifications.categories.'.$category->value.'.defaults', []);

            foreach (NotificationPreferenceChannel::cases() as $channel) {
                $enabled = (bool) ($defaults[$channel->value] ?? false);

                $rows[] = UserNotificationPreference::query()->firstOrCreate(
                    [
                        'user_id' => $user->getKey(),
                        'category' => $category->value,
                        'channel' => $channel->value,
                    ],
                    ['enabled' => $enabled],
                );
            }
        }

        return $rows;
    }

    /**
     * @return list<array{category: string, channel: string, enabled: bool, critical: bool, updated_at: string|null}>
     */
    public function listForUser(User $user): array
    {
        $this->ensureDefaults($user);

        return UserNotificationPreference::query()
            ->where('user_id', $user->getKey())
            ->orderBy('category')
            ->orderBy('channel')
            ->get()
            ->map(static function (UserNotificationPreference $pref): array {
                return [
                    'category' => $pref->category->value,
                    'channel' => $pref->channel->value,
                    'enabled' => $pref->enabled,
                    'critical' => $pref->category->isCritical(),
                    'updated_at' => $pref->updated_at?->toIso8601String(),
                ];
            })
            ->all();
    }

    /**
     * @param  list<array{category: string, channel: string, enabled: bool}>  $updates
     */
    public function updateMany(User $user, array $updates): void
    {
        DB::transaction(function () use ($user, $updates): void {
            User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $this->ensureDefaults($user);

            foreach ($updates as $update) {
                $category = NotificationPreferenceCategory::tryFrom($update['category']);
                $channel = NotificationPreferenceChannel::tryFrom($update['channel']);

                if ($category === null || $channel === null) {
                    throw ValidationException::withMessages([
                        'preferences' => __('Unknown notification category or channel.'),
                    ]);
                }

                $enabled = $update['enabled'];

                if ($category->isCritical() && $enabled === false) {
                    throw ValidationException::withMessages([
                        'preferences' => __('Critical security notifications cannot be disabled.'),
                    ]);
                }

                if ($category->isTransactionalBilling() && $channel === NotificationPreferenceChannel::Email && $enabled === false) {
                    // Product policy: transactional billing email remains enforced.
                    $enabled = true;
                }

                UserNotificationPreference::query()->updateOrCreate(
                    [
                        'user_id' => $user->getKey(),
                        'category' => $category->value,
                        'channel' => $channel->value,
                    ],
                    ['enabled' => $enabled],
                );
            }

            $this->audit->write('settings.notification_updated', (string) $user->getKey(), $user, metadata: [
                'count' => count($updates),
            ]);
            $this->analytics->record('settings.notification_preference_changed', (string) $user->getKey());
        });
    }

    public function updateMarketingConsent(User $user, bool $optIn, ?string $source = null): IdentityPreference
    {
        return DB::transaction(function () use ($user, $optIn, $source): IdentityPreference {
            /** @var IdentityPreference $pref */
            $pref = IdentityPreference::query()->firstOrCreate(
                ['user_id' => $user->getKey()],
                [
                    'marketing_consent' => false,
                    'terms_accepted' => $user->terms_accepted_at !== null,
                    'terms_accepted_at' => $user->terms_accepted_at,
                ],
            );

            $locked = IdentityPreference::query()->whereKey($pref->getKey())->lockForUpdate()->firstOrFail();
            $locked->forceFill([
                'marketing_consent' => $optIn,
                'marketing_consent_at' => $optIn ? now() : null,
                'marketing_consent_source' => $source ?? (string) config('settings.marketing.default_source', 'settings'),
                'marketing_policy_version' => (string) config('settings.marketing.policy_version', '2026-08-01'),
            ])->save();

            $user->forceFill([
                'marketing_consent_at' => $optIn ? now() : null,
            ])->save();

            // Keep typed marketing notification channel aligned with consent.
            UserNotificationPreference::query()->updateOrCreate(
                [
                    'user_id' => $user->getKey(),
                    'category' => NotificationPreferenceCategory::Marketing->value,
                    'channel' => NotificationPreferenceChannel::Email->value,
                ],
                ['enabled' => $optIn],
            );

            $this->audit->write('settings.marketing_consent_updated', (string) $user->getKey(), $user, metadata: [
                'opt_in' => $optIn,
                'source' => $locked->marketing_consent_source,
                'policy_version' => $locked->marketing_policy_version,
            ]);

            return $locked->fresh() ?? $locked;
        });
    }

    /**
     * @return array{ok: bool, detail: string}
     */
    public function registryHealth(): array
    {
        $configured = array_keys((array) config('settings.notifications.categories', []));
        $enum = NotificationPreferenceCategory::values();
        $missing = array_diff($enum, $configured);
        $extra = array_diff($configured, $enum);

        if ($missing !== [] || $extra !== []) {
            return [
                'ok' => false,
                'detail' => 'Notification category registry mismatch.',
            ];
        }

        return [
            'ok' => true,
            'detail' => count($enum).' categories registered.',
        ];
    }
}
