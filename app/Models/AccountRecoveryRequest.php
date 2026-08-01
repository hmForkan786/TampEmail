<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AccountRecoveryReasonCode;
use App\Enums\AccountRecoveryStatus;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Admin-assisted account recovery request. Sensitive fields are encrypted at rest.
 *
 * @property string $id
 * @property string|null $user_id
 * @property string $claimed_email_hash
 * @property string|null $new_email_encrypted
 * @property AccountRecoveryStatus $status
 * @property AccountRecoveryReasonCode $reason_code
 * @property string|null $evidence_notes_encrypted
 * @property string|null $submitted_ip_hash
 * @property string|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property string|null $second_reviewed_by
 * @property Carbon|null $second_reviewed_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $expires_at
 * @property array<int, array<string, mixed>>|null $review_history
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AccountRecoveryRequest extends Model
{
    use HasUuid;

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'claimed_email_hash',
        'new_email_encrypted',
        'status',
        'reason_code',
        'evidence_notes_encrypted',
        'submitted_ip_hash',
        'reviewed_by',
        'reviewed_at',
        'second_reviewed_by',
        'second_reviewed_at',
        'completed_at',
        'expires_at',
        'review_history',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'new_email_encrypted',
        'evidence_notes_encrypted',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => AccountRecoveryStatus::class,
            'reason_code' => AccountRecoveryReasonCode::class,
            'new_email_encrypted' => 'encrypted',
            'evidence_notes_encrypted' => 'encrypted',
            'reviewed_at' => 'datetime',
            'second_reviewed_at' => 'datetime',
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
            'review_history' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * Append an immutable review history entry (never rewrite prior entries).
     *
     * @param  array<string, mixed>  $entry
     */
    public function appendReviewHistory(array $entry): void
    {
        $history = $this->review_history ?? [];
        $history[] = $entry;
        $this->review_history = $history;
    }
}
