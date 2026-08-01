<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentProviderEventStatus;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $provider
 * @property string $provider_event_id
 * @property string $event_type
 * @property string $payload_hash
 * @property PaymentProviderEventStatus $status
 * @property Carbon $received_at
 * @property Carbon|null $processed_at
 * @property Carbon|null $failed_at
 * @property int $attempts
 * @property string|null $last_error
 * @property array<string, mixed>|null $payload_redacted
 */
class PaymentProviderEvent extends BaseModel
{
    protected $table = 'payment_provider_events';

    /** @var list<string> */
    protected $fillable = [
        'provider',
        'provider_event_id',
        'event_type',
        'payload_hash',
        'status',
        'received_at',
        'processed_at',
        'failed_at',
        'attempts',
        'last_error',
        'payload_redacted',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'status' => PaymentProviderEventStatus::class,
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
            'failed_at' => 'datetime',
            'attempts' => 'integer',
            'payload_redacted' => 'array',
        ]);
    }
}
