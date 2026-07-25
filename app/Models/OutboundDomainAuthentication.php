<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OutboundDomainAuthState;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $domain_id
 * @property string $provider
 * @property OutboundDomainAuthState $state
 * @property OutboundDomainAuthState $ownership_state
 * @property OutboundDomainAuthState $spf_state
 * @property OutboundDomainAuthState $dkim_state
 * @property OutboundDomainAuthState $dmarc_state
 * @property string|null $expected_spf
 * @property array<int, array<string, string>>|null $expected_dkim
 * @property string|null $expected_ownership
 * @property string|null $failure_code
 * @property int $record_version
 * @property Carbon|null $last_checked_at
 * @property Carbon|null $last_verified_at
 * @property Carbon|null $next_check_at
 */
class OutboundDomainAuthentication extends BaseModel
{
    protected $table = 'outbound_domain_authentications';

    protected $fillable = [
        'domain_id',
        'provider',
        'state',
        'ownership_state',
        'spf_state',
        'dkim_state',
        'dmarc_state',
        'expected_spf',
        'expected_dkim',
        'expected_ownership',
        'failure_code',
        'record_version',
        'last_checked_at',
        'last_verified_at',
        'next_check_at',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'state' => OutboundDomainAuthState::class,
            'ownership_state' => OutboundDomainAuthState::class,
            'spf_state' => OutboundDomainAuthState::class,
            'dkim_state' => OutboundDomainAuthState::class,
            'dmarc_state' => OutboundDomainAuthState::class,
            'expected_dkim' => 'array',
            'record_version' => 'integer',
            'last_checked_at' => 'datetime',
            'last_verified_at' => 'datetime',
            'next_check_at' => 'datetime',
        ]);
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    public function allowsSending(): bool
    {
        $allowDegraded = (bool) config('outbound.domain_authentication.allow_degraded_dmarc', true);

        return $this->state->allowsSending($allowDegraded);
    }
}
