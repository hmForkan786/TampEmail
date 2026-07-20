<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Append-only log of authenticated API requests for analytics, security, and auditing.
 *
 * @property string $id
 * @property string|null $api_key_id
 * @property string|null $user_id
 * @property string $method
 * @property string $endpoint
 * @property string $ip_address
 * @property string|null $user_agent
 * @property int $response_status
 * @property int $response_time_ms
 * @property int|null $request_size_bytes
 * @property int|null $response_size_bytes
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 *
 * @property-read ApiKey|null $apiKey
 * @property-read User|null $user
 */
class ApiRequestLog extends BaseModel
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'api_request_logs';

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
        'api_key_id',
        'user_id',
        'method',
        'endpoint',
        'ip_address',
        'user_agent',
        'response_status',
        'response_time_ms',
        'request_size_bytes',
        'response_size_bytes',
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
            'response_status' => 'integer',
            'response_time_ms' => 'integer',
            'request_size_bytes' => 'integer',
            'response_size_bytes' => 'integer',
            'metadata' => 'array',
        ]);
    }

    /**
     * Get the API key used for the request.
     */
    public function apiKey(): BelongsTo
    {
        return $this->belongsTo(ApiKey::class);
    }

    /**
     * Get the user attributed to the request.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope a query to successful API requests.
     */
    #[Scope]
    protected function successful(Builder $query): void
    {
        $query->where('response_status', '>=', 200)
            ->where('response_status', '<', 300);
    }

    /**
     * Scope a query to failed API requests.
     */
    #[Scope]
    protected function failed(Builder $query): void
    {
        $query->where('response_status', '>=', 400);
    }

    /**
     * Scope a query to API requests for the given endpoint.
     */
    #[Scope]
    protected function endpoint(Builder $query, string $endpoint): void
    {
        $query->where('endpoint', $endpoint);
    }

    /**
     * Scope a query to the most recent API requests first.
     */
    #[Scope]
    protected function recent(Builder $query): void
    {
        $query->orderByDesc('created_at');
    }

    /**
     * Determine whether the API request was successful.
     */
    public function isSuccessful(): bool
    {
        return $this->response_status >= 200 && $this->response_status < 300;
    }

    /**
     * Determine whether the API request failed.
     */
    public function isFailed(): bool
    {
        return $this->response_status >= 400;
    }

    /**
     * Determine whether the API request used an API key.
     */
    public function hasApiKey(): bool
    {
        return $this->api_key_id !== null;
    }
}
