<?php

declare(strict_types=1);

namespace App\Http\Requests\Outbound;

use Illuminate\Foundation\Http\FormRequest;

final class SendScheduledMessageNowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'schedule_version' => ['required', 'integer', 'min:1'],
        ];
    }
}
