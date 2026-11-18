<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $webhook_endpoint_id
 * @property string $event_id
 * @property string $event_type
 * @property string $status
 * @property int $attempt_count
 * @property Carbon|null $next_attempt_at
 * @property int|null $response_status
 * @property string|null $failure_code
 * @property array<string, mixed> $payload
 * @property Carbon|null $delivered_at
 * @property Carbon|null $created_at
 * @property-read WebhookEndpoint|null $endpoint
 */
final class WebhookDelivery extends BaseModel
{
    protected $fillable = ['webhook_endpoint_id', 'event_id', 'event_type', 'status', 'attempt_count', 'next_attempt_at', 'response_status', 'response_excerpt', 'failure_code', 'payload', 'delivered_at'];

    protected function casts(): array
    {
        return array_merge(parent::casts(), ['payload' => 'array', 'next_attempt_at' => 'datetime', 'delivered_at' => 'datetime']);
    }

    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(WebhookEndpoint::class, 'webhook_endpoint_id');
    }
}
