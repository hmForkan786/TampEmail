<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\MailServer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin MailServer */
final class MailServerResource extends JsonResource
{
    /**
     * @param  mixed  $resource
     */
    protected static function newCollection($resource): MailServerCollection
    {
        return new MailServerCollection($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'hostname' => $this->hostname,
            'provider' => $this->provider,
            'protocol' => $this->protocol,
            'is_active' => $this->is_active,
            'operational_status' => $this->operationalStatusEnum()->value,
            'priority' => $this->priority,
            'pool_key' => $this->pool_key,
            'max_inboxes' => $this->max_inboxes,
            'max_throughput' => $this->max_throughput,
            'max_connections' => $this->max_connections,
            'timeout_seconds' => $this->timeout_seconds,
            'last_health_check_at' => $this->last_health_check_at,
            'health_score' => $this->health_score,
            'drain_started_at' => $this->drain_started_at,
            'consecutive_failures' => $this->consecutive_failures,
            'last_failure_at' => $this->last_failure_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
