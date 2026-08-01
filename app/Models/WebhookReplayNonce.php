<?php

declare(strict_types=1);

namespace App\Models;

class WebhookReplayNonce extends BaseModel
{
    protected $fillable = ['provider', 'nonce_hash', 'signing_key_id', 'request_fingerprint', 'first_seen_at', 'expires_at', 'source_ip_hash'];

    protected function casts(): array
    {
        return array_merge(parent::casts(), ['first_seen_at' => 'datetime', 'expires_at' => 'datetime']);
    }
}
