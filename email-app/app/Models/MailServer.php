<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Carbon;

/**
 * Inbound mail server definition for the email ingestion pipeline.
 *
 * @property string $id
 * @property string $name
 * @property string $hostname
 * @property string $provider
 * @property string $protocol
 * @property bool $is_active
 * @property int $priority
 * @property int $max_connections
 * @property int $timeout_seconds
 * @property Carbon|null $last_health_check_at
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class MailServer extends BaseModel
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'mail_servers';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'hostname',
        'provider',
        'protocol',
        'is_active',
        'priority',
        'max_connections',
        'timeout_seconds',
        'last_health_check_at',
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
            'is_active' => 'boolean',
            'priority' => 'integer',
            'max_connections' => 'integer',
            'timeout_seconds' => 'integer',
            'last_health_check_at' => 'datetime',
            'metadata' => 'array',
        ]);
    }

    /**
     * Scope a query to only include active mail servers.
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Scope a query to mail servers with the given provider.
     */
    #[Scope]
    protected function provider(Builder $query, string $provider): void
    {
        $query->where('provider', $provider);
    }

    /**
     * Scope a query to mail servers using the given protocol.
     */
    #[Scope]
    protected function protocol(Builder $query, string $protocol): void
    {
        $query->where('protocol', $protocol);
    }

    /**
     * Scope a query to order mail servers by priority descending.
     */
    #[Scope]
    protected function ordered(Builder $query): void
    {
        $query->orderByDesc('priority');
    }

    /**
     * Determine whether the mail server is active.
     */
    public function isActive(): bool
    {
        return $this->is_active;
    }

    /**
     * Determine whether the mail server is healthy.
     */
    public function healthy(): bool
    {
        if ($this->last_health_check_at === null) {
            return false;
        }

        return $this->last_health_check_at->gte(now()->subMinutes(10));
    }
}
