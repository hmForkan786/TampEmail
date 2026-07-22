<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ProcessingStatus;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Inbound email metadata record linked to a disposable inbox.
 *
 * @property string $id
 * @property string $inbox_id
 * @property string $message_id
 * @property string|null $sender_name
 * @property string $sender_email
 * @property string $recipient_email
 * @property string|null $subject
 * @property Carbon $received_at
 * @property bool $has_html
 * @property bool $has_text
 * @property bool $has_attachments
 * @property int $attachment_count
 * @property int $size_bytes
 * @property ProcessingStatus $processing_status
 * @property string|null $spam_score
 * @property array<string, mixed>|null $headers
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Inbox $inbox
 * @property-read EmailBody|null $body
 * @property-read Collection<int, Attachment> $attachments
 * @property-read Collection<int, EmailEvent> $events
 * @property-read Collection<int, EmailProcessingLog> $processingLogs
 */
class Email extends BaseModel
{
    use HasFactory;
    use SoftDeletes;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'emails';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'inbox_id',
        'message_id',
        'sender_name',
        'sender_email',
        'recipient_email',
        'subject',
        'received_at',
        'has_html',
        'has_text',
        'has_attachments',
        'attachment_count',
        'size_bytes',
        'processing_status',
        'spam_score',
        'headers',
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
            'received_at' => 'datetime',
            'has_html' => 'boolean',
            'has_text' => 'boolean',
            'has_attachments' => 'boolean',
            'attachment_count' => 'integer',
            'size_bytes' => 'integer',
            'spam_score' => 'decimal:2',
            'processing_status' => ProcessingStatus::class,
            'headers' => 'array',
            'metadata' => 'array',
        ]);
    }

    /**
     * Get the inbox that owns the email.
     */
    public function inbox(): BelongsTo
    {
        return $this->belongsTo(Inbox::class);
    }

    /**
     * Get the body content associated with the email.
     */
    public function body(): HasOne
    {
        return $this->hasOne(EmailBody::class);
    }

    /**
     * Get the attachments associated with the email.
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }

    /**
     * Get the lifecycle events associated with the email.
     */
    public function events(): HasMany
    {
        return $this->hasMany(EmailEvent::class);
    }

    /**
     * Get the processing logs associated with the email.
     */
    public function processingLogs(): HasMany
    {
        return $this->hasMany(EmailProcessingLog::class);
    }

    /**
     * Scope a query to emails awaiting processing.
     */
    #[Scope]
    protected function received(Builder $query): void
    {
        $query->where('processing_status', ProcessingStatus::Received);
    }

    /**
     * Scope a query to fully processed emails.
     */
    #[Scope]
    protected function processed(Builder $query): void
    {
        $query->where('processing_status', ProcessingStatus::Stored);
    }

    /**
     * Scope a query to emails that include attachments.
     */
    #[Scope]
    protected function withAttachments(Builder $query): void
    {
        $query->where('has_attachments', true);
    }

    /**
     * Determine whether the email includes attachments.
     */
    public function hasAttachments(): bool
    {
        return $this->has_attachments;
    }

    /**
     * Determine whether the email includes HTML content.
     */
    public function hasHtml(): bool
    {
        return $this->has_html;
    }

    /**
     * Determine whether the email includes plain text content.
     */
    public function hasText(): bool
    {
        return $this->has_text;
    }

    /**
     * Determine whether the email has completed processing.
     */
    public function isProcessed(): bool
    {
        return $this->processing_status === ProcessingStatus::Stored;
    }
}
