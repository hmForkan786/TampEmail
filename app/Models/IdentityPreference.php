<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Registration consent preferences (terms vs marketing kept separate).
 *
 * @property string $id
 * @property string $user_id
 * @property bool $marketing_consent
 * @property Carbon|null $marketing_consent_at
 * @property bool $terms_accepted
 * @property Carbon|null $terms_accepted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class IdentityPreference extends Model
{
    use HasUuid;

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'marketing_consent',
        'marketing_consent_at',
        'terms_accepted',
        'terms_accepted_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'marketing_consent' => 'boolean',
            'terms_accepted' => 'boolean',
            'marketing_consent_at' => 'datetime',
            'terms_accepted_at' => 'datetime',
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
