<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MailServerOperationalStatus;
use App\Services\MailServer\MailServerHealthScorer;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Inbound mail server inventory for pool assignment (not an MTA control plane).
 *
 * @property string $id
 * @property string $name
 * @property string $hostname
 * @property string $provider
 * @property string $protocol
 * @property bool $is_active
 * @property MailServerOperationalStatus $operational_status
 * @property int $priority
 * @property string|null $pool_key
 * @property int|null $max_inboxes
 * @property int|null $max_throughput
 * @property int $max_connections
 * @property int $timeout_seconds
 * @property Carbon|null $last_health_check_at
 * @property int $health_score
 * @property Carbon|null $drain_started_at
 * @property int $consecutive_failures
 * @property Carbon|null $last_failure_at
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, Inbox> $inboxes
 */
class MailServer extends BaseModel
{
    use HasFactory;

    protected $table = 'mail_servers';

    protected static function booted(): void
    {
        static::saving(function (MailServer $server): void {
            $raw = $server->getAttributes()['operational_status'] ?? null;
            if ($raw === null || $raw === '') {
                $server->operational_status = $server->is_active
                    ? MailServerOperationalStatus::Active
                    : MailServerOperationalStatus::Disabled;
            }

            $server->health_score = app(MailServerHealthScorer::class)->score($server);
        });
    }

    protected $fillable = [
        'name',
        'hostname',
        'provider',
        'protocol',
        'is_active',
        'operational_status',
        'priority',
        'pool_key',
        'max_inboxes',
        'max_throughput',
        'max_connections',
        'timeout_seconds',
        'last_health_check_at',
        'health_score',
        'drain_started_at',
        'consecutive_failures',
        'last_failure_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'is_active' => 'boolean',
            'operational_status' => MailServerOperationalStatus::class,
            'priority' => 'integer',
            'max_inboxes' => 'integer',
            'max_throughput' => 'integer',
            'max_connections' => 'integer',
            'timeout_seconds' => 'integer',
            'last_health_check_at' => 'datetime',
            'health_score' => 'integer',
            'drain_started_at' => 'datetime',
            'consecutive_failures' => 'integer',
            'last_failure_at' => 'datetime',
            'metadata' => 'array',
        ]);
    }

    public function inboxes(): HasMany
    {
        return $this->hasMany(Inbox::class);
    }

    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true)
            ->where('operational_status', MailServerOperationalStatus::Active->value);
    }

    #[Scope]
    protected function provider(Builder $query, string $provider): void
    {
        $query->where('provider', $provider);
    }

    #[Scope]
    protected function protocol(Builder $query, string $protocol): void
    {
        $query->where('protocol', $protocol);
    }

    #[Scope]
    protected function ordered(Builder $query): void
    {
        $query->orderByDesc('priority');
    }

    public function isActive(): bool
    {
        return $this->is_active
            && $this->operationalStatusEnum() === MailServerOperationalStatus::Active;
    }

    public function operationalStatusEnum(): MailServerOperationalStatus
    {
        return $this->operational_status;
    }

    /**
     * Freshness + score gate for assignment eligibility display.
     */
    public function healthy(): bool
    {
        $window = max(1, (int) config('mail_servers.health_window_minutes', 10));
        $min = (int) config('mail_servers.selection.min_health_score', 50);

        if ($this->last_health_check_at === null) {
            return false;
        }

        if ($this->last_health_check_at->lt(now()->subMinutes($window))) {
            return false;
        }

        return (int) $this->health_score >= $min;
    }
}
