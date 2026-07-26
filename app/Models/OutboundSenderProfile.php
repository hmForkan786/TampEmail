<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Per-inbox outbound sender identity profile (display name, reply-to, signature).
 *
 * @property string $id
 * @property string $user_id
 * @property string $inbox_id
 * @property string $name
 * @property string|null $display_name
 * @property string|null $reply_to_address
 * @property string|null $reply_to_name
 * @property string|null $signature_text
 * @property string|null $signature_html
 * @property bool $include_on_send
 * @property bool $include_on_reply
 * @property bool $include_on_forward
 * @property bool $is_default
 * @property bool $is_active
 * @property int $version
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read User $user
 * @property-read Inbox $inbox
 */
class OutboundSenderProfile extends BaseModel
{
    use SoftDeletes;

    protected $table = 'outbound_sender_profiles';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'inbox_id',
        'name',
        'display_name',
        'reply_to_address',
        'reply_to_name',
        'signature_text',
        'signature_html',
        'include_on_send',
        'include_on_reply',
        'include_on_forward',
        'is_default',
        'is_active',
        'version',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'include_on_send' => 'boolean',
            'include_on_reply' => 'boolean',
            'include_on_forward' => 'boolean',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'version' => 'integer',
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

    public function isUsable(): bool
    {
        return $this->is_active && $this->deleted_at === null;
    }
}
