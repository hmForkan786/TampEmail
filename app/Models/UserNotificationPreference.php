<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\NotificationPreferenceCategory;
use App\Enums\NotificationPreferenceChannel;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Typed per-user notification preference (category × channel).
 *
 * @property string $id
 * @property string $user_id
 * @property NotificationPreferenceCategory $category
 * @property NotificationPreferenceChannel $channel
 * @property bool $enabled
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class UserNotificationPreference extends Model
{
    use HasUuid;

    protected $table = 'user_notification_preferences';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'category',
        'channel',
        'enabled',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => NotificationPreferenceCategory::class,
            'channel' => NotificationPreferenceChannel::class,
            'enabled' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
