<?php

declare(strict_types=1);

namespace App\Http\Requests\Billing;

use App\Models\ApiKey;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StartCheckoutRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (! $this->filled('idempotency_key') && $this->headers->has('Idempotency-Key')) {
            $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'plan_id' => ['required', 'uuid'],
            'gateway' => ['required', 'string', 'max:32'],
            'billing_cycle' => ['sometimes', Rule::in(['monthly', 'yearly'])],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:128', 'not_regex:/[\r\n\0]/'],
            'success_url' => ['required', 'string', 'max:2048'],
            'cancel_url' => ['required', 'string', 'max:2048'],
            'return_url' => ['nullable', 'string', 'max:2048'],
            'client_reference' => ['nullable', 'string', 'max:128'],
            'metadata' => ['sometimes', 'array:campaign,source'],
            'metadata.campaign' => ['nullable', 'string', 'max:64'],
            'metadata.source' => ['nullable', 'string', 'max:64'],
            'amount' => ['prohibited'],
            'amount_minor' => ['prohibited'],
            'currency' => ['prohibited'],
            'discount' => ['prohibited'],
            'tax' => ['prohibited'],
        ];
    }

    public function authorize(): bool
    {
        return $this->attributes->get('apiKey') instanceof ApiKey;
    }
}
