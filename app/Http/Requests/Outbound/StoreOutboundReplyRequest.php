<?php

declare(strict_types=1);

namespace App\Http\Requests\Outbound;

use App\Http\Responses\ApiErrorResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

final class StoreOutboundReplyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            ApiErrorResponse::make('validation_failed', 'The given data was invalid.', 422, $validator->errors()->toArray())
        );
    }

    protected function prepareForValidation(): void
    {
        if (! $this->filled('idempotency_key') && $this->headers->has('Idempotency-Key')) {
            $this->merge(['idempotency_key' => $this->headers->get('Idempotency-Key')]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'string', 'max:128'],
            'subject' => ['sometimes', 'nullable', 'string', 'max:998'],
            'text_body' => ['sometimes', 'nullable', 'string'],
            'html_body' => ['sometimes', 'nullable', 'string'],
            'cc' => ['sometimes', 'array'],
            'cc.*' => ['required', 'string', 'max:255'],
            'to' => ['prohibited'],
            'from' => ['prohibited'],
            'from_address' => ['prohibited'],
            'in_reply_to' => ['prohibited'],
            'references' => ['prohibited'],
            'state' => ['prohibited'],
        ];
    }
}
