<?php

declare(strict_types=1);

namespace App\Http\Requests\Inbox;

use App\Http\Responses\ApiErrorResponse;
use App\Models\Domain;
use Carbon\Carbon;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

final class StoreOwnedInboxRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(ApiErrorResponse::make('validation_failed', 'The given data was invalid.', 422, $validator->errors()->toArray()));
    }

    public function rules(): array
    {
        return [
            'domain_id' => ['required', 'uuid'],
            'local_part' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9][A-Za-z0-9.!#$%&\'*+\/?^_`{|}~-]*$/'],
            'expires_at' => ['sometimes', 'nullable', 'date', 'after:now'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) return;
            $domain = Domain::query()->active()->registrationAllowed()->whereKey($this->string('domain_id')->toString())->first();
            if ($domain === null) {
                $validator->errors()->add('domain_id', 'The selected domain is unavailable.');
                return;
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
}
