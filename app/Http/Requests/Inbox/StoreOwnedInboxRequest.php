<?php

declare(strict_types=1);

namespace App\Http\Requests\Inbox;

use App\Http\Responses\ApiErrorResponse;
use App\Models\Domain;
use App\Models\Inbox;
use Carbon\Carbon;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

final class StoreOwnedInboxRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(ApiErrorResponse::make('validation_failed', 'The given data was invalid.', 422, $validator->errors()->toArray()));
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'domain_id' => ['required', 'uuid'],
            'local_part' => ['sometimes', 'nullable', 'string', 'min:1', 'max:64', 'regex:/^[a-z0-9][a-z0-9.!#$%&\'*+\/?^_`{|}~-]*$/'],
            'expires_at' => ['sometimes', 'nullable', 'date', 'after:now'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $domain = Domain::query()->active()->registrationAllowed()->whereKey($this->string('domain_id')->toString())->first();
            if ($domain === null) {
                $validator->errors()->add('domain_id', 'The selected domain is unavailable.');

                return;
            }
            if ($this->filled('local_part')) {
                $localPart = $this->string('local_part')->toString();
                if (in_array($localPart, config('inbox.reserved_local_parts', []), true)) {
                    $validator->errors()->add('local_part', 'This inbox alias is reserved.');
                }
                if (Inbox::withTrashed()->where('full_address', $localPart.'@'.strtolower((string) $domain->domain))->exists()) {
                    $validator->errors()->add('local_part', 'This inbox alias is already in use.');
                }
            }
            if ($this->filled('expires_at')) {
                $expiresAt = Carbon::parse($this->input('expires_at'));
                $maxHours = (int) config('inbox_lifetime.max_absolute_lifetime_hours', 0);
                if ($expiresAt->gt(now()->addHours($maxHours))) {
                    $validator->errors()->add('expires_at', 'The expiration exceeds the maximum lifetime.');
                }
            }
        });
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('local_part') && is_string($this->input('local_part'))) {
            $this->merge(['local_part' => strtolower(trim($this->input('local_part')))]);
        }
    }
}
