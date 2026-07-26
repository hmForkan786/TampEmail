<?php

declare(strict_types=1);

namespace App\Http\Requests\Outbound;

final class UpdateOutboundSenderProfileRequest extends StoreOutboundSenderProfileRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'version' => ['required', 'integer', 'min:1'],
            'inbox_id' => ['prohibited'],
            'name' => ['sometimes', 'string', 'max:100'],
        ]);
    }
}
