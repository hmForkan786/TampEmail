<?php

declare(strict_types=1);

namespace App\Services\Billing\Webhook;

use App\Models\WebhookReplayNonce;
use Illuminate\Database\QueryException;

final class WebhookReplayProtectionService
{
    public function reserve(string $provider, string $nonce, string $fingerprint, ?string $keyId, ?string $sourceIp, int $ttlSeconds): string
    {
        if (strlen($nonce) < 16 || strlen($nonce) > 200 || preg_match('/^[A-Za-z0-9._:-]+$/D', $nonce) !== 1) {
            return 'invalid_nonce';
        }
        $hash = hash_hmac('sha256', $nonce, (string) config('app.key'));
        try {
            WebhookReplayNonce::query()->create([
                'provider' => $provider, 'nonce_hash' => $hash, 'signing_key_id' => $keyId,
                'request_fingerprint' => $fingerprint, 'first_seen_at' => now(), 'expires_at' => now()->addSeconds($ttlSeconds),
                'source_ip_hash' => $sourceIp ? hash_hmac('sha256', $sourceIp, (string) config('app.key')) : null,
            ]);

            return 'first_seen';
        } catch (QueryException $exception) {
            $existing = WebhookReplayNonce::query()->where('provider', $provider)->where('nonce_hash', $hash)->first();
            if ($existing === null) {
                throw $exception;
            }

            return hash_equals($existing->request_fingerprint, $fingerprint) ? 'exact_retry' : 'conflicting_replay';
        }
    }

    public function prune(int $batchSize, bool $dryRun = false): int
    {
        $query = WebhookReplayNonce::query()->where('expires_at', '<', now())->limit($batchSize);
        $count = (clone $query)->count();
        if (! $dryRun) {
            $query->delete();
        }

        return $count;
    }
}
