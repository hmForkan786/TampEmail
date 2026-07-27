<?php

declare(strict_types=1);

namespace App\Services\Webhook;

use App\Exceptions\OutboundSendException;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\Audit\AuditLogWriter;
use App\Services\Entitlement\EntitlementService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class WebhookEndpointService
{
    public function __construct(
        private readonly EntitlementService $entitlements,
        private readonly AuditLogWriter $audit,
        private readonly WebhookSecurityValidator $security,
        private readonly WebhookDeliveryStateMachine $stateMachine,
    ) {}

    /** @param  array<string, mixed>  $data
     * @return array{endpoint: WebhookEndpoint, secret: string} */
    public function create(User $user, array $data): array
    {
        try {
            return DB::transaction(function () use ($user, $data): array {
                $locked = $this->lockUser($user);
                $this->assertAccess($locked);
                WebhookEventRegistry::assertSupported($data['events']);
                $this->security->assertSafeUrl($data['url']);

                $isActive = $data['is_active'] ?? true;
                if ($isActive) {
                    $this->assertEndpointSlotAvailable($locked);
                }

                $secret = Str::random(48);
                $endpoint = WebhookEndpoint::query()->create([
                    'user_id' => $locked->id,
                    'name' => $data['name'],
                    'url' => $data['url'],
                    'events' => $data['events'],
                    'is_active' => $isActive,
                    'secret_encrypted' => $secret,
                ]);

                $this->audit->write('commercial.webhook_endpoint_created', (string) $locked->getKey(), $endpoint, null, null, [
                    'active' => $isActive,
                    'event_count' => count($data['events']),
                ]);

                return ['endpoint' => $endpoint, 'secret' => $secret];
            });
        } catch (OutboundSendException $exception) {
            if ($exception->errorCode === 'plan_limit_reached') {
                $this->auditEndpointLimitReached($user);
            }

            throw $exception;
        }
    }

    /** @param  array<string, mixed>  $data */
    public function update(WebhookEndpoint $endpoint, User $user, array $data): WebhookEndpoint
    {
        return DB::transaction(function () use ($endpoint, $user, $data): WebhookEndpoint {
            $locked = $this->lockUser($user);
            $this->assertAccess($locked);
            $endpoint = $this->lockEndpoint($endpoint, $user);

            if (isset($data['url'])) {
                $this->security->assertSafeUrl($data['url']);
            }
            if (isset($data['events'])) {
                WebhookEventRegistry::assertSupported($data['events']);
            }

            $updates = array_intersect_key($data, array_flip(['name', 'url', 'events']));
            if ($updates !== []) {
                $endpoint->forceFill($updates)->save();
            }

            return $endpoint->refresh();
        });
    }

    public function enable(WebhookEndpoint $endpoint, User $user): WebhookEndpoint
    {
        try {
            return DB::transaction(function () use ($endpoint, $user): WebhookEndpoint {
                $locked = $this->lockUser($user);
                $this->assertAccess($locked);
                $endpoint = $this->lockEndpoint($endpoint, $user);

                if ($endpoint->is_active) {
                    return $endpoint;
                }

                $this->assertEndpointSlotAvailable($locked);
                $endpoint->forceFill(['is_active' => true])->save();

                return $endpoint->refresh();
            });
        } catch (OutboundSendException $exception) {
            if ($exception->errorCode === 'plan_limit_reached') {
                $this->auditEndpointLimitReached($user);
            }

            throw $exception;
        }
    }

    public function disable(WebhookEndpoint $endpoint, User $user): WebhookEndpoint
    {
        return DB::transaction(function () use ($endpoint, $user): WebhookEndpoint {
            $locked = $this->lockUser($user);
            $this->assertAccess($locked);
            $endpoint = $this->lockEndpoint($endpoint, $user);
            $endpoint->forceFill(['is_active' => false])->save();
            $this->cancelPendingDeliveries($endpoint, 'endpoint_disabled');

            return $endpoint->refresh();
        });
    }

    /** @return array{endpoint: WebhookEndpoint, secret: string} */
    public function rotateSecret(WebhookEndpoint $endpoint, User $user): array
    {
        return DB::transaction(function () use ($endpoint, $user): array {
            $locked = $this->lockUser($user);
            $this->assertAccess($locked);
            $endpoint = $this->lockEndpoint($endpoint, $user);

            $secret = Str::random(48);
            $endpoint->forceFill(['secret_encrypted' => $secret])->save();

            $this->audit->write('commercial.webhook_secret_rotated', (string) $locked->getKey(), $endpoint, null, null, [
                'webhook_endpoint_id' => (string) $endpoint->getKey(),
            ]);

            return ['endpoint' => $endpoint->refresh(), 'secret' => $secret];
        });
    }

    public function delete(WebhookEndpoint $endpoint, User $user): void
    {
        DB::transaction(function () use ($endpoint, $user): void {
            $locked = $this->lockUser($user);
            $this->assertAccess($locked);
            $endpoint = $this->lockEndpoint($endpoint, $user);
            $this->cancelPendingDeliveries($endpoint, 'endpoint_deleted');
            $endpoint->delete();
        });
    }

    public function assertAccess(User $user): void
    {
        if ($this->entitlements->allows($user, 'webhook.access')) {
            return;
        }

        $this->audit->write('commercial.webhook_access_denied', (string) $user->getKey(), null, null, null, ['feature' => 'webhook.access']);
        throw new OutboundSendException('feature_not_available', 'Your current plan does not include webhooks.', 403);
    }

    public function assertUrl(string $url): void
    {
        $this->security->assertSafeUrl($url);
    }

    public function activeEndpointCount(User $user): int
    {
        return WebhookEndpoint::query()
            ->where('user_id', $user->getKey())
            ->where('is_active', true)
            ->count();
    }

    private function assertEndpointSlotAvailable(User $user): void
    {
        $used = $this->activeEndpointCount($user);
        $limit = $this->entitlements->limit($user, 'webhook.max_endpoints');

        if ($used >= $limit) {
            throw new OutboundSendException('plan_limit_reached', 'Webhook endpoint limit reached.', 409);
        }
    }

    private function auditEndpointLimitReached(User $user): void
    {
        $used = $this->activeEndpointCount($user);
        $limit = $this->entitlements->limit($user, 'webhook.max_endpoints');
        $this->audit->write('commercial.webhook_endpoint_limit_reached', (string) $user->getKey(), null, null, null, [
            'feature' => 'webhook.max_endpoints',
            'limit' => $limit,
            'used' => $used,
            'remaining' => 0,
        ]);
    }

    private function cancelPendingDeliveries(WebhookEndpoint $endpoint, string $reason): void
    {
        WebhookDelivery::query()
            ->where('webhook_endpoint_id', $endpoint->getKey())
            ->whereIn('status', ['pending', 'queued', 'retry_scheduled'])
            ->each(function (WebhookDelivery $delivery) use ($reason, $endpoint): void {
                if ($this->stateMachine->canTransition($delivery->status, 'cancelled')) {
                    $this->stateMachine->transition($delivery, 'cancelled', ['failure_code' => $reason]);
                    $this->audit->write('commercial.webhook_delivery_cancelled', (string) $endpoint->user_id, $delivery, null, null, ['reason' => $reason]);
                }
            });
    }

    private function lockUser(User $user): User
    {
        return User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
    }

    private function lockEndpoint(WebhookEndpoint $endpoint, User $user): WebhookEndpoint
    {
        $locked = WebhookEndpoint::query()
            ->whereKey($endpoint->getKey())
            ->where('user_id', $user->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        return $locked;
    }
}
