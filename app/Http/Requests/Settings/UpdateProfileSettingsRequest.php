<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateProfileSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:120'],
            'locale' => ['required', 'string', Rule::in((array) config('settings.locales', ['en']))],
            'timezone' => ['required', 'string', Rule::in((array) config('settings.timezones', ['UTC']))],
            'updated_at' => ['nullable', 'string'],
            'email' => ['prohibited'],
            'status' => ['prohibited'],
            'platform_role' => ['prohibited'],
            'pending_email' => ['prohibited'],
        ];

        if (config('settings.avatar.enabled') === true) {
            $rules['avatar'] = [
                'nullable',
                'file',
                'max:'.max(1, (int) config('settings.avatar.max_kb', 512)),
                'mimetypes:'.implode(',', (array) config('settings.avatar.mimes', ['image/jpeg', 'image/png', 'image/webp'])),
            ];
        } else {
            $rules['avatar'] = ['prohibited'];
        }

        return $rules;
    }
}
