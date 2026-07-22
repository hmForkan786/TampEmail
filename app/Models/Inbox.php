<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InboxType;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Disposable inbox mapped to a domain and optionally owned by a user.
 *
 * @property string $id
 * @property string $domain_id
 * @property string|null $user_id
 * @property string|null $mail_server_id
 * @property string $local_part
 * @property string $full_address
 * @property string|null $display_name
 * @property InboxType $inbox_type
 * @property Carbon|null $expires_at
 * @property Carbon|null $last_received_at
 * @property int $message_count
 * @property bool $is_active
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Domain $domain
 * @property-read User|null $user
 * @property-read MailServer|null $mailServer
 * @property-read Collection<int, Email> $emails
 */
class Inbox extends BaseModel
{
    use HasFactory;
    use SoftDeletes;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'inboxes';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'domain_id',
        'user_id',
        'mail_server_id',
        'local_part',
        'full_address',
        'display_name',
        'inbox_type',
        'expires_at',
        'last_received_at',
        'message_count',
        'is_active',
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
            'inbox_type' => InboxType::class,
            'expires_at' => 'datetime',
            'last_received_at' => 'datetime',
            'message_count' => 'integer',
            'is_active' => 'boolean',
            'metadata' => 'array',
        ]);
    }

    /**
     * Get the domain that owns the inbox.
     */
    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    /**
     * Get the user that owns the inbox.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the mail server assigned to the inbox.
     */
    public function mailServer(): BelongsTo
    {
        return $this->belongsTo(MailServer::class);
    }

    /**
     * Get the emails received by the inbox.
     */
    public function emails(): HasMany
    {
        return $this->hasMany(Email::class);
    }

    /**
     * Scope a query to only include active inboxes.
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Scope a query to inboxes owned by the given user.
     *
     * Anonymous inboxes (`user_id` null) never match — ownership is not inferred.
     */
    #[Scope]
    protected function ownedBy(Builder $query, string $userId): void
    {
        $query->where('user_id', $userId);
    }

    /**
     * Scope a query to inboxes that are active and not expired for owner visibility.
     */
    #[Scope]
    protected function visibleToOwner(Builder $query): void
    {
        $query->where('is_active', true)
            ->where(function (Builder $inner): void {
                $inner->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            });
    }

    /**
     * Scope a query to only include expired inboxes.
     */
    #[Scope]
    protected function expired(Builder $query): void
    {
        $query->whereNotNull('expires_at')
            ->where('expires_at', '<=', now());
    }

    /**
     * Scope a query to only include permanent inbox types.
     */
    #[Scope]
    protected function permanent(Builder $query): void
    {
        $query->whereIn('inbox_type', [
            InboxType::Private,
            InboxType::Reserved,
        ]);
    }

    /**
     * Scope a query to only include temporary inboxes.
     */
    #[Scope]
    protected function temporary(Builder $query): void
    {
        $query->where('inbox_type', InboxType::Temporary);
    }

    /**
     * Determine whether the inbox is active.
     */
    public function isActive(): bool
    {
        return $this->is_active;
    }

    /**
     * Determine whether the inbox has expired.
     */
    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * Determine whether the inbox is temporary.
     */
    public function isTemporary(): bool
    {
        return $this->inbox_type === InboxType::Temporary;
    }

    /**
     * Determine whether the inbox is permanent.
     */
    public function isPermanent(): bool
    {
        return $this->inbox_type !== InboxType::Temporary;
    }
}
