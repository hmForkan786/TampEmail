<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InboxType;
use App\Enums\Theme;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Per-user application preferences and personalization settings.
 *
 * @property string $id
 * @property string $user_id
 * @property string $language
 * @property string $timezone
 * @property Theme $theme
 * @property int $auto_refresh_seconds
 * @property InboxType $default_inbox_type
 * @property array<string, mixed>|null $notification_settings
 * @property array<string, mixed>|null $privacy_settings
 * @property array<string, mixed>|null $ui_settings
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @property-read User $user
 */
class UserPreference extends BaseModel
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'user_preferences';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'language',
        'timezone',
        'theme',
        'auto_refresh_seconds',
        'default_inbox_type',
        'notification_settings',
        'privacy_settings',
        'ui_settings',
        'metadata',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'theme' => Theme::class,
            'default_inbox_type' => InboxType::class,
            'auto_refresh_seconds' => 'integer',
            'notification_settings' => 'array',
            'privacy_settings' => 'array',
            'ui_settings' => 'array',
            'metadata' => 'array',
        ]);
    }

    /**
     * Get the user that owns the preferences.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope a query to preferences using the dark theme.
     */
    #[Scope]
    protected function dark(Builder $query): void
    {
        $query->where('theme', Theme::Dark);
    }

    /**
     * Scope a query to preferences using the light theme.
     */
    #[Scope]
    protected function light(Builder $query): void
    {
        $query->where('theme', Theme::Light);
    }

    /**
     * Scope a query to preferences using the system theme.
     */
    #[Scope]
    protected function system(Builder $query): void
    {
        $query->where('theme', Theme::System);
    }

    /**
     * Determine whether the user prefers dark mode.
     */
    public function isDarkMode(): bool
    {
        return $this->theme === Theme::Dark;
    }

    /**
     * Determine whether the user prefers light mode.
     */
    public function isLightMode(): bool
    {
        return $this->theme === Theme::Light;
    }

    /**
     * Get the auto-refresh interval in seconds.
     */
    public function refreshInterval(): int
    {
        return $this->auto_refresh_seconds;
    }
}
