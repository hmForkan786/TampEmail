<?php

declare(strict_types=1);

namespace App\Http\Requests\MailServer;

use App\Enums\MailProtocol;
use App\Enums\MailProvider;
use App\Http\Responses\ApiErrorResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

final class UpdateMailServerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            ApiErrorResponse::make(
                'validation_failed',
                'The given data was invalid.',
                422,
                $validator->errors()->toArray(),
            )
        );
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:100'],
            'hostname' => ['sometimes', 'string', 'max:255'],
            'provider' => ['sometimes', 'string', 'in:'.implode(',', array_column(MailProvider::cases(), 'value'))],
            'protocol' => ['sometimes', 'string', 'in:'.implode(',', array_column(MailProtocol::cases(), 'value'))],
            'is_active' => ['sometimes', 'boolean'],
            'priority' => ['sometimes', 'integer', 'min:0'],
            'max_connections' => ['sometimes', 'integer', 'min:1'],
            'timeout_seconds' => ['sometimes', 'integer', 'min:1'],
            'last_health_check_at' => ['sometimes', 'nullable', 'date'],
            'metadata' => ['sometimes', 'nullable', 'array'],
            'pool_key' => ['sometimes', 'nullable', 'string', 'max:255', 'not_regex:/^\s*$/'],
            'max_inboxes' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }
}
