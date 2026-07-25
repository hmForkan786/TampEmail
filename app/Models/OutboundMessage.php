<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OutboundMessageState;
use App\Enums\OutboundOperation;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Outbound email message with queued delivery lifecycle.
 *
 * @property string $id
 * @property string $user_id
 * @property string $inbox_id
 * @property string|null $source_email_id
 * @property OutboundOperation $operation
 * @property OutboundMessageState $state
 * @property string $idempotency_key
 * @property string $request_fingerprint
 * @property string $from_address
 * @property string|null $from_display_name
 * @property list<string> $to_recipients
 * @property list<string>|null $cc_recipients
 * @property list<string>|null $bcc_recipients
 * @property string|null $subject
 * @property string|null $text_body
 * @property string|null $html_body
 * @property string|null $in_reply_to
 * @property string|null $references
 * @property string|null $provider
 * @property string|null $provider_message_id
 * @property int $attempt_count
 * @property Carbon|null $queued_at
 * @property Carbon|null $sending_at
 * @property Carbon|null $transport_attempted_at
 * @property Carbon|null $sent_at
 * @property Carbon|null $delivered_at
 * @property Carbon|null $failed_at
 * @property Carbon|null $cancelled_at
 * @property Carbon|null $reconciliation_flagged_at
 * @property string|null $reconciliation_note
 * @property string|null $failure_code
 * @property string|null $failure_message
 * @property list<string>|null $attachment_ids
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read Inbox $inbox
 * @property-read Email|null $sourceEmail
 */
class OutboundMessage extends BaseModel
{
    protected $table = 'outbound_messages';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'inbox_id',
        'source_email_id',
        'operation',
        'state',
        'idempotency_key',
        'request_fingerprint',
        'from_address',
        'from_display_name',
        'to_recipients',
        'cc_recipients',
        'bcc_recipients',
        'subject',
        'text_body',
        'html_body',
        'in_reply_to',
        'references',
        'provider',
        'provider_message_id',
        'attempt_count',
        'queued_at',
        'sending_at',
        'transport_attempted_at',
        'sent_at',
        'delivered_at',
        'failed_at',
        'cancelled_at',
        'reconciliation_flagged_at',
        'reconciliation_note',
        'failure_code',
        'failure_message',
        'attachment_ids',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'operation' => OutboundOperation::class,
            'state' => OutboundMessageState::class,
            'to_recipients' => 'array',
            'cc_recipients' => 'array',
            'bcc_recipients' => 'array',
            'attachment_ids' => 'array',
            'metadata' => 'array',
            'attempt_count' => 'integer',
            'queued_at' => 'datetime',
            'sending_at' => 'datetime',
            'transport_attempted_at' => 'datetime',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'failed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'reconciliation_flagged_at' => 'datetime',
        ]);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function inbox(): BelongsTo
    {
        return $this->belongsTo(Inbox::class);
    }

    public function sourceEmail(): BelongsTo
    {
        return $this->belongsTo(Email::class, 'source_email_id');
    }

    public function recipientCount(): int
    {
        return count($this->to_recipients ?? [])
            + count($this->cc_recipients ?? [])
            + count($this->bcc_recipients ?? []);
    }
}
