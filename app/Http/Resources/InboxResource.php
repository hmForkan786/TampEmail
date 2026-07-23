<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Inbox */
final class InboxResource extends JsonResource
{
    protected static function newCollection($resource): InboxCollection
    {
        return new InboxCollection($resource);
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'domain_id' => $this->domain_id,
            'address' => $this->full_address,
            'full_address' => $this->full_address,
            'is_active' => $this->is_active,
            'expires_at' => $this->expires_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'email_count' => (int) ($this->email_count ?? 0),
            'unread_count' => (int) ($this->unread_count ?? 0),
            'safe_attachment_count' => (int) ($this->safe_attachment_count ?? 0),
        ];
    }
}
