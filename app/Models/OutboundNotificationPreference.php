<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class OutboundNotificationPreference extends BaseModel
{
    protected $fillable = ['user_id', 'notifications_enabled', 'in_app_enabled', 'email_enabled', 'events', 'version'];

    protected function casts(): array
    {
        return array_merge(parent::casts(), ['notifications_enabled' => 'boolean', 'in_app_enabled' => 'boolean', 'email_enabled' => 'boolean', 'events' => 'array', 'version' => 'integer']);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
