<?php

declare(strict_types=1);

namespace App\Http\Requests\Inbox;

use App\Http\Responses\ApiErrorResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

final class ListOwnedInboxesRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    protected function prepareForValidation(): void
    {
        foreach (['is_active', 'expired', 'is_expired', 'has_unread'] as $field) {
            if (is_string($this->input($field))) {
                $value = strtolower($this->input($field));
                if ($value === 'true') $this->merge([$field => true]);
                if ($value === 'false') $this->merge([$field => false]);
            }
        }
        if (!$this->has('expired') && $this->has('is_expired')) {
            $this->merge(['expired' => $this->input('is_expired')]);
        }
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(ApiErrorResponse::make(
            'validation_failed', 'The given data was invalid.', 422, $validator->errors()->toArray()
        ));
    }

    public function rules(): array
    {
        return [
            'is_active' => ['sometimes', 'boolean'],
            'expired' => ['sometimes', 'boolean'],
            'is_expired' => ['sometimes', 'boolean'],
            'domain_id' => ['sometimes', 'uuid'],
            'has_unread' => ['sometimes', 'boolean'],
            'created_after' => ['sometimes', 'nullable', 'date'],
            'created_before' => ['sometimes', 'nullable', 'date'],
            'sort' => ['sometimes', 'in:created_at,updated_at,expires_at,full_address,is_active,email_count,unread_count,safe_attachment_count'],
            'direction' => ['sometimes', 'in:asc,desc'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
