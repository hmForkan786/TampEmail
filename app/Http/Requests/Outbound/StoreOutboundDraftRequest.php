<?php

declare(strict_types=1);

namespace App\Http\Requests\Outbound;

use App\Http\Responses\ApiErrorResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreOutboundDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(ApiErrorResponse::make('validation_failed', 'The given data was invalid.', 422, $validator->errors()->toArray()));
    }

    public function rules(): array
    {
        return [
            'inbox_id' => ['required', 'uuid'], 'operation' => ['required', 'in:send,reply,forward'], 'source_email_id' => ['nullable', 'uuid'],
            'to' => ['sometimes', 'array'], 'to.*' => ['string', 'max:255'], 'cc' => ['sometimes', 'array'], 'cc.*' => ['string', 'max:255'], 'bcc' => ['sometimes', 'array'], 'bcc.*' => ['string', 'max:255'],
            'subject' => ['sometimes', 'nullable', 'string', 'max:998'], 'text_body' => ['sometimes', 'nullable', 'string'], 'html_body' => ['sometimes', 'nullable', 'string'],
            'attachment_ids' => ['sometimes', 'array'], 'attachment_ids.*' => ['uuid'],
            'headers' => ['prohibited'], 'from' => ['prohibited'], 'from_address' => ['prohibited'], 'return_path' => ['prohibited'], 'in_reply_to' => ['prohibited'], 'references' => ['prohibited'], 'raw_mime' => ['prohibited'],
        ];
    }
}
