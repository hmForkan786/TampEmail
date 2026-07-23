<?php
declare(strict_types=1);
namespace App\Http\Requests\Inbox;
use App\Http\Responses\ApiErrorResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
final class RenewOwnedInboxRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    protected function failedValidation(Validator $validator): void { throw new HttpResponseException(ApiErrorResponse::make('validation_failed', 'The given data was invalid.', 422, $validator->errors()->toArray())); }
    public function rules(): array { return ['expires_at' => ['required', 'date', 'after:now']]; }
}
