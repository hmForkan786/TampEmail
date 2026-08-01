<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Support\Carbon;

/** @property string $provider @property string $key_id @property string $algorithm @property string|null $secret_encrypted @property string $status @property string $environment @property Carbon $valid_from @property Carbon|null $valid_until */
class ProviderSigningKey extends BaseModel
{
    protected $fillable = ['provider', 'key_id', 'algorithm', 'secret_encrypted', 'public_key', 'status', 'environment', 'valid_from', 'valid_until', 'metadata'];

    protected $hidden = ['secret_encrypted'];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'secret_encrypted' => 'encrypted',
            'valid_from' => 'datetime',
            'valid_until' => 'datetime',
            'metadata' => 'array',
        ]);
    }
}
