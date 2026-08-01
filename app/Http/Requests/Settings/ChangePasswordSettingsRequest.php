<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Services\Identity\PasswordPolicy;
use Illuminate\Foundation\Http\FormRequest;

final class ChangePasswordSettingsRequest extends FormRequest
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
        return [
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', PasswordPolicy::rules()],
        ];
    }
}
