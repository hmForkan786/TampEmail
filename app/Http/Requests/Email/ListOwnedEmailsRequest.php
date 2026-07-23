<?php

declare(strict_types=1);

namespace App\Http\Requests\Email;

use App\Http\Responses\ApiErrorResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

final class ListOwnedEmailsRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    protected function prepareForValidation(): void
    {
        foreach (['is_read', 'has_attachments'] as $field) {
            if (is_string($this->input($field))) {
                $value = strtolower($this->input($field));
                if ($value === 'true') $this->merge([$field => true]);
                if ($value === 'false') $this->merge([$field => false]);
            }
        }
    }
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(ApiErrorResponse::make('validation_failed', 'The given data was invalid.', 422, $validator->errors()->toArray()));
    }
    public function rules(): array
    {
        return [
            'is_read' => ['sometimes', 'boolean'],
            'from' => ['sometimes', 'nullable', 'string', 'max:320'],
            'to' => ['sometimes', 'nullable', 'string', 'max:320'],
            'subject' => ['sometimes', 'nullable', 'string', 'max:200'],
            'message_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'received_after' => ['sometimes', 'nullable', 'date'],
            'received_before' => ['sometimes', 'nullable', 'date'],
            'has_attachments' => ['sometimes', 'boolean'],
            'sort' => ['sometimes', 'in:received_at,created_at,subject,sender_email,message_id,is_read'],
            'direction' => ['sometimes', 'in:asc,desc'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
