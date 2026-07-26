<?php

declare(strict_types=1);

namespace App\Http\Requests\Outbound;

use Illuminate\Foundation\Http\FormRequest;

final class RescheduleOutboundMessageRequest extends FormRequest
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
            'local_date' => ['required', 'string', 'regex:/^\d{4}-\d{2}-\d{2}$/'],
            'local_time' => ['required', 'string', 'regex:/^\d{2}:\d{2}(:\d{2})?$/'],
            'timezone' => ['required', 'string', 'max:64'],
        ];
    }
}
