<?php

declare(strict_types=1);

namespace App\Services\Settings;

use App\Models\User;
use App\Services\Audit\AuditLogWriter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Profile settings mutations — never accepts email as a direct write.
 */
final class UserProfileSettingsService
{
    public function __construct(
        private readonly AuditLogWriter $audit,
        private readonly SettingsAnalyticsRecorder $analytics,
    ) {}

    /**
     * @param  array{
     *     name?: string,
     *     locale?: string,
     *     timezone?: string,
     *     updated_at?: string|null,
     *     email?: mixed,
     *     status?: mixed,
     *     platform_role?: mixed
     * }  $input
     */
    public function update(User $user, array $input, ?UploadedFile $avatar = null): User
    {
        if (array_key_exists('email', $input)) {
            throw ValidationException::withMessages([
                'email' => __('Email cannot be changed through profile settings. Use the email change flow.'),
            ]);
        }

        $locales = (array) config('settings.locales', ['en']);
        $timezones = (array) config('settings.timezones', ['UTC']);

        $name = isset($input['name']) ? trim((string) $input['name']) : $user->name;
        $locale = isset($input['locale']) ? trim((string) $input['locale']) : $user->locale;
        $timezone = isset($input['timezone']) ? trim((string) $input['timezone']) : $user->timezone;

        if ($name === '' || mb_strlen($name) > 120) {
            throw ValidationException::withMessages(['name' => __('A valid name is required.')]);
        }

        if (! in_array($locale, $locales, true)) {
            throw ValidationException::withMessages(['locale' => __('The selected locale is invalid.')]);
        }

        if (! in_array($timezone, $timezones, true)) {
            throw ValidationException::withMessages(['timezone' => __('The selected timezone is invalid.')]);
        }

        return DB::transaction(function () use ($user, $name, $locale, $timezone, $input, $avatar): User {
            /** @var User $locked */
            $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            if (! empty($input['updated_at'])) {
                $expected = (string) $input['updated_at'];
                $actual = optional($locked->updated_at)?->toIso8601String();
                if ($actual !== null && $expected !== $actual) {
                    throw ValidationException::withMessages([
                        'updated_at' => __('This profile was updated elsewhere. Reload and try again.'),
                    ]);
                }
            }

            $changes = [];
            if ($locked->name !== $name) {
                $changes['name'] = true;
            }
            if ($locked->locale !== $locale) {
                $changes['locale'] = true;
            }
            if ($locked->timezone !== $timezone) {
                $changes['timezone'] = true;
            }

            $locked->fill([
                'name' => $name,
                'locale' => $locale,
                'timezone' => $timezone,
            ]);

            if ($avatar !== null && config('settings.avatar.enabled') === true) {
                $path = $this->storeAvatar($locked, $avatar);
                $locked->avatar = $path;
                $changes['avatar'] = true;
            }

            $locked->save();

            $this->audit->write('settings.profile_updated', (string) $locked->getKey(), $locked, metadata: [
                'fields' => array_keys($changes),
            ]);

            if ($this->isProfileComplete($locked)) {
                $this->analytics->record('settings.profile_completed', (string) $locked->getKey());
            }

            return $locked->fresh() ?? $locked;
        });
    }

    public function isProfileComplete(User $user): bool
    {
        return trim((string) $user->name) !== ''
            && trim((string) $user->locale) !== ''
            && trim((string) $user->timezone) !== ''
            && $user->email_verified_at !== null;
    }

    private function storeAvatar(User $user, UploadedFile $avatar): string
    {
        $disk = (string) config('settings.avatar.disk', 'local');
        $safeName = Str::slug(pathinfo($avatar->getClientOriginalName(), PATHINFO_FILENAME));
        $safeName = $safeName !== '' ? $safeName : 'avatar';
        $filename = $safeName.'-'.Str::random(8).'.'.$avatar->getClientOriginalExtension();
        $directory = 'private/settings/avatars/'.$user->getKey();

        $path = $avatar->storeAs($directory, $filename, $disk);

        if (! is_string($path) || $path === '') {
            throw ValidationException::withMessages(['avatar' => __('Unable to store avatar.')]);
        }

        if (is_string($user->avatar) && $user->avatar !== '') {
            Storage::disk($disk)->delete($user->avatar);
        }

        return $path;
    }
}
