<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property string $user_id
 * @property string $name
 * @property string $url
 * @property list<string> $events
 * @property bool $is_active
 * @property string $secret_encrypted
 * @property Carbon|null $last_delivery_at
 * @property-read User|null $user
 */
final class WebhookEndpoint extends BaseModel
{
    use SoftDeletes;

    protected $fillable = ['user_id', 'name', 'url', 'secret_encrypted', 'events', 'is_active', 'last_delivery_at'];

    protected function casts(): array
    {
        return array_merge(parent::casts(), ['events' => 'array', 'is_active' => 'boolean', 'secret_encrypted' => 'encrypted', 'last_delivery_at' => 'datetime']);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }
}
