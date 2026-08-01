<?php

declare(strict_types=1);

namespace App\Services\Settings;

use App\Actions\ApiKey\RotateApiKeyAction;
use App\DTOs\ApiKey\ApiKeyIssuanceResult;
use App\Models\ApiKey;
use App\Models\User;
use App\Notifications\Settings\ApiKeyLifecycleNotification;
use App\Services\ApiKey\ApiKeyScopeRegistry;
use App\Services\ApiKey\ApiKeyService;
use App\Services\Audit\AuditLogWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Owner-facing API key lifecycle using existing ApiKey services/actions.
 */
final class SettingsApiKeyService
{
    public function __construct(
        private readonly ApiKeyService $apiKeys,
        private readonly RotateApiKeyAction $rotate,
        private readonly AuditLogWriter $audit,
        private readonly SettingsAnalyticsRecorder $analytics,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function listForUser(User $user): array
    {
        return ApiKey::query()
            ->where('user_id', $user->getKey())
            ->orderByDesc('created_at')
            ->get()
            ->map(static function (ApiKey $key): array {
                return [
                    'id' => $key->getKey(),
                    'name' => $key->name,
                    'prefix' => $key->key_prefix,
                    'scopes' => $key->permissions,
                    'last_used_at' => $key->last_used_at?->toIso8601String(),
                    'expires_at' => $key->expires_at?->toIso8601String(),
                    'created_at' => $key->created_at?->toIso8601String(),
                    'revoked_at' => $key->revoked_at?->toIso8601String(),
                    'active' => $key->isActive(),
                ];
            })
            ->all();
    }

    /**
     * @param  list<string>|null  $scopes
     */
    public function create(User $user, string $name, ?array $scopes, string $password): ApiKeyIssuanceResult
    {
        $this->assertPassword($user, $password);
        $scopes = $scopes ?? (array) config('settings.api_keys.default_scopes', []);
        $scopes = ApiKeyScopeRegistry::authorizeForOwner($user, $scopes);

        $result = $this->apiKeys->issue(
            userId: (string) $user->getKey(),
            name: $name,
            permissions: $scopes,
            user: $user,
        );

        $this->audit->write('settings.api_key_created', (string) $user->getKey(), $result->apiKey, metadata: [
            'prefix' => $result->apiKey->key_prefix,
        ]);
        $this->analytics->record('settings.api_key_action', (string) $user->getKey(), dimensions: [
            'action' => 'created',
        ]);
        $user->notify(new ApiKeyLifecycleNotification('created', $result->apiKey->name, $result->apiKey->key_prefix));

        return $result;
    }

    public function rotate(User $user, ApiKey $apiKey, string $password): ApiKeyIssuanceResult
    {
        $this->assertPassword($user, $password);
        $this->assertOwner($user, $apiKey);

        $result = $this->rotate->execute($user, $apiKey);

        $this->audit->write('settings.api_key_rotated', (string) $user->getKey(), $result->apiKey, metadata: [
            'prefix' => $result->apiKey->key_prefix,
            'replaced_prefix' => $apiKey->key_prefix,
        ]);
        $this->analytics->record('settings.api_key_action', (string) $user->getKey(), dimensions: [
            'action' => 'rotated',
        ]);
        $user->notify(new ApiKeyLifecycleNotification('rotated', $result->apiKey->name, $result->apiKey->key_prefix));

        return $result;
    }

    public function revoke(User $user, ApiKey $apiKey, string $password): ApiKey
    {
        $this->assertPassword($user, $password);
        $this->assertOwner($user, $apiKey);

        return DB::transaction(function () use ($user, $apiKey): ApiKey {
            /** @var ApiKey $locked */
            $locked = ApiKey::query()->whereKey($apiKey->getKey())->lockForUpdate()->firstOrFail();
            $this->assertOwner($user, $locked);

            if ($locked->isActive()) {
                $locked->revoked_at = now();
                $locked->save();
            }

            $this->audit->write('settings.api_key_revoked', (string) $user->getKey(), $locked, metadata: [
                'prefix' => $locked->key_prefix,
            ]);
            $this->analytics->record('settings.api_key_action', (string) $user->getKey(), dimensions: [
                'action' => 'revoked',
            ]);
            $user->notify(new ApiKeyLifecycleNotification('revoked', $locked->name, $locked->key_prefix));

            return $locked->fresh() ?? $locked;
        });
    }

    public function ownedKey(User $user, string $apiKeyId): ApiKey
    {
        $key = ApiKey::query()
            ->whereKey($apiKeyId)
            ->where('user_id', $user->getKey())
            ->firstOrFail();

        return $key;
    }

    private function assertOwner(User $user, ApiKey $apiKey): void
    {
        if ((string) $apiKey->user_id !== (string) $user->getKey()) {
            abort(404);
        }
    }

    private function assertPassword(User $user, string $password): void
    {
        if (config('settings.api_keys.require_password', true) !== true) {
            return;
        }

        if (! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages([
                'password' => __('Please confirm your password.'),
            ]);
        }
    }
}
