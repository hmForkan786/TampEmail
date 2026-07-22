<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\MailServer */
final class MailServerResource extends JsonResource
{
    /**
     * @param  mixed  $resource
     */
    protected static function newCollection($resource): MailServerCollection
    {
        return new MailServerCollection($resource);
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'hostname' => $this->hostname,
            'provider' => $this->provider,
            'protocol' => $this->protocol,
            'is_active' => $this->is_active,
            'priority' => $this->priority,
            'pool_key' => $this->pool_key,
            'max_inboxes' => $this->max_inboxes,
            'max_connections' => $this->max_connections,
            'timeout_seconds' => $this->timeout_seconds,
            'last_health_check_at' => $this->last_health_check_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
