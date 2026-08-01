<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Hashed login attempt record for security history (no raw IP/email/UA).
 *
 * @property string $id
 * @property string|null $user_id
 * @property string $email_hash
 * @property bool $success
 * @property string|null $failure_reason_code
 * @property string|null $ip_hash
 * @property string|null $user_agent_hash
 * @property Carbon $occurred_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class LoginAttempt extends Model
{
    use HasUuid;

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'email_hash',
        'success',
        'failure_reason_code',
        'ip_hash',
        'user_agent_hash',
        'occurred_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'success' => 'boolean',
            'occurred_at' => 'datetime',
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
