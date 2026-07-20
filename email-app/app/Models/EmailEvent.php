<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EmailEventType;
use App\Enums\EventSource;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Append-only lifecycle event for an inbound email.
 *
 * @property string $id
 * @property string $email_id
 * @property EmailEventType $event_type
 * @property EventSource|null $event_source
 * @property string|null $actor_user_id
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property array<string, mixed>|null $payload
 * @property Carbon $occurred_at
 * @property Carbon|null $created_at
 * @property-read Email $email
 * @property-read User|null $actorUser
 */
class EmailEvent extends BaseModel
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'email_events';

    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = true;

    /**
     * The name of the "updated at" column.
     *
     * @var string|null
     */
    public const UPDATED_AT = null;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'email_id',
        'event_type',
        'event_source',
        'actor_user_id',
        'ip_address',
        'user_agent',
        'payload',
        'occurred_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'event_type' => EmailEventType::class,
            'event_source' => EventSource::class,
            'payload' => 'array',
            'occurred_at' => 'datetime',
        ]);
    }

    /**
     * Get the email associated with the event.
     */
    public function email(): BelongsTo
    {
        return $this->belongsTo(Email::class);
    }

    /**
     * Get the user who triggered the event.
     */
    public function actorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /**
     * Scope a query to events of the given type.
     */
    #[Scope]
    protected function type(Builder $query, EmailEventType $type): void
    {
        $query->where('event_type', $type);
    }

    /**
     * Scope a query to events from the given source.
     */
    #[Scope]
    protected function source(Builder $query, EventSource $source): void
    {
        $query->where('event_source', $source);
    }

    /**
     * Scope a query to the most recent events first.
     */
    #[Scope]
    protected function recent(Builder $query): void
    {
        $query->orderByDesc('occurred_at');
    }

    /**
     * Determine whether the event has an associated actor user.
     */
    public function hasActor(): bool
    {
        return $this->actor_user_id !== null;
    }

    /**
     * Determine whether the event includes a payload.
     */
    public function hasPayload(): bool
    {
        return $this->payload !== null;
    }
}
