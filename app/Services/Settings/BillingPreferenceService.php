<?php

declare(strict_types=1);

namespace App\Services\Settings;

use App\Models\User;
use App\Models\UserBillingPreference;
use App\Notifications\Settings\BillingEmailChangedNotification;
use App\Services\Audit\AuditLogWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Billing preference metadata only — never mutates invoices or entitlements.
 */
final class BillingPreferenceService
{
    public function __construct(
        private readonly AuditLogWriter $audit,
        private readonly SettingsAnalyticsRecorder $analytics,
    ) {}

    public function forUser(User $user): UserBillingPreference
    {
        return UserBillingPreference::query()->firstOrCreate(
            ['user_id' => $user->getKey()],
            [
                'billing_email' => $user->email,
                'invoice_locale' => $user->locale,
            ],
        );
    }

    /**
     * @param  array{
     *     billing_email?: string|null,
     *     invoice_name?: string|null,
     *     invoice_address?: string|null,
     *     invoice_locale?: string|null,
     *     tax_identifier?: string|null
     * }  $input
     */
    public function update(User $user, array $input): UserBillingPreference
    {
        $locales = (array) config('settings.locales', ['en']);

        return DB::transaction(function () use ($user, $input, $locales): UserBillingPreference {
            $pref = $this->forUser($user);
            /** @var UserBillingPreference $locked */
            $locked = UserBillingPreference::query()->whereKey($pref->getKey())->lockForUpdate()->firstOrFail();

            $billingEmail = array_key_exists('billing_email', $input)
                ? ($input['billing_email'] !== null ? strtolower(trim((string) $input['billing_email'])) : null)
                : $locked->billing_email;

            if ($billingEmail !== null && $billingEmail !== '' && ! filter_var($billingEmail, FILTER_VALIDATE_EMAIL)) {
                throw ValidationException::withMessages([
                    'billing_email' => __('Enter a valid billing email.'),
                ]);
            }

            $invoiceLocale = array_key_exists('invoice_locale', $input)
                ? ($input['invoice_locale'] !== null ? trim((string) $input['invoice_locale']) : null)
                : $locked->invoice_locale;

            if ($invoiceLocale !== null && $invoiceLocale !== '' && ! in_array($invoiceLocale, $locales, true)) {
                throw ValidationException::withMessages([
                    'invoice_locale' => __('The selected invoice locale is invalid.'),
                ]);
            }

            $previousEmail = $locked->billing_email;

            $payload = [
                'billing_email' => $billingEmail ?: null,
                'invoice_name' => array_key_exists('invoice_name', $input)
                    ? ($input['invoice_name'] !== null ? trim((string) $input['invoice_name']) : null)
                    : $locked->invoice_name,
                'invoice_address' => array_key_exists('invoice_address', $input)
                    ? ($input['invoice_address'] !== null ? trim((string) $input['invoice_address']) : null)
                    : $locked->invoice_address,
                'invoice_locale' => $invoiceLocale ?: null,
            ];

            if (array_key_exists('tax_identifier', $input)) {
                $tax = $input['tax_identifier'] !== null ? trim((string) $input['tax_identifier']) : null;
                $payload['tax_identifier_encrypted'] = $tax !== '' ? $tax : null;
            }

            $locked->fill($payload)->save();

            $this->audit->write('settings.billing_preferences_updated', (string) $user->getKey(), $locked, metadata: [
                'fields' => array_keys($payload),
            ]);
            $this->analytics->record('settings.billing_action_started', (string) $user->getKey(), dimensions: [
                'action' => 'preferences_updated',
            ]);

            if ($previousEmail !== $locked->billing_email && is_string($locked->billing_email) && $locked->billing_email !== '') {
                $user->notify(new BillingEmailChangedNotification);
            }

            return $locked->fresh() ?? $locked;
        });
    }

    /**
     * Safe projection for UI/admin (tax identifier masked).
     *
     * @return array<string, mixed>
     */
    public function summary(User $user): array
    {
        $pref = $this->forUser($user);
        $tax = $pref->tax_identifier_encrypted;

        return [
            'billing_email' => $pref->billing_email,
            'invoice_name' => $pref->invoice_name,
            'invoice_address' => $pref->invoice_address,
            'invoice_locale' => $pref->invoice_locale,
            'tax_identifier_masked' => is_string($tax) && $tax !== ''
                ? str_repeat('*', max(0, mb_strlen($tax) - 4)).mb_substr($tax, -4)
                : null,
            'updated_at' => $pref->updated_at?->toIso8601String(),
        ];
    }
}
