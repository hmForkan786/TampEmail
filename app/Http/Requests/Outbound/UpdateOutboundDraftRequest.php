<?php

declare(strict_types=1);

namespace App\Http\Requests\Outbound;

final class UpdateOutboundDraftRequest extends StoreOutboundDraftRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), ['version' => ['required', 'integer', 'min:1'], 'inbox_id' => ['sometimes', 'uuid'], 'operation' => ['sometimes', 'in:send,reply,forward']]);
    }
}
