<?php

declare(strict_types=1);

namespace App\Http\Requests\Outbound;

use App\Http\Responses\ApiErrorResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

final class StoreOutboundMessageRequest extends FormRequest
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
        $requireKey = (bool) config('outbound.require_idempotency_key', true);

        return [
            'inbox_id' => ['required', 'uuid'],
            'idempotency_key' => [$requireKey ? 'required' : 'sometimes', 'string', 'max:128'],
            'to' => ['required', 'array', 'min:1'],
            'to.*' => ['required', 'string', 'max:255'],
            'cc' => ['sometimes', 'array'],
            'cc.*' => ['required', 'string', 'max:255'],
            'bcc' => ['sometimes', 'array'],
            'bcc.*' => ['required', 'string', 'max:255'],
            'subject' => ['required', 'string', 'max:998'],
            'text_body' => ['sometimes', 'nullable', 'string'],
            'html_body' => ['sometimes', 'nullable', 'string'],
            'from_display_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'sender_profile_id' => ['sometimes', 'nullable', 'uuid'],
            'from' => ['prohibited'],
            'from_address' => ['prohibited'],
            'state' => ['prohibited'],
            'provider' => ['prohibited'],
        ];
    }
}
