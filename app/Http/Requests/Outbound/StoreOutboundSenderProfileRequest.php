<?php

declare(strict_types=1);

namespace App\Http\Requests\Outbound;

use App\Http\Responses\ApiErrorResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreOutboundSenderProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(ApiErrorResponse::make('validation_failed', 'The given data was invalid.', 422, $validator->errors()->toArray()));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'inbox_id' => ['required', 'uuid'],
            'name' => ['required', 'string', 'max:100'],
            'display_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'reply_to_address' => ['sometimes', 'nullable', 'string', 'max:320'],
            'reply_to_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'signature_text' => ['sometimes', 'nullable', 'string'],
            'signature_html' => ['sometimes', 'nullable', 'string'],
            'include_on_send' => ['sometimes', 'boolean'],
            'include_on_reply' => ['sometimes', 'boolean'],
            'include_on_forward' => ['sometimes', 'boolean'],
            'is_default' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'from' => ['prohibited'],
            'from_address' => ['prohibited'],
            'headers' => ['prohibited'],
        ];
    }
}
