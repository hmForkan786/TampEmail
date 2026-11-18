<?php

declare(strict_types=1);
use App\Actions\ApiKey\CreateApiKeyAction;
use App\Actions\Inbox\CreateInboxAction;
use App\DTOs\Inbox\CreateInboxData;
use App\DTOs\Inbox\InboxMutationContext;
use App\Exceptions\ApiKeyQuotaExceededException;
use App\Exceptions\EligibleMailServerUnavailableException;
use App\Exceptions\InboxQuotaExceededException;
use App\Exceptions\OutboundSendException;
use App\Models\User;
use App\Services\Webhook\WebhookEndpointService;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$options = getopt('', ['run:', 'worker:', 'scenario:', 'input:']);
$run = (string) ($options['run'] ?? '');
$worker = (string) ($options['worker'] ?? '');
$scenario = (string) ($options['scenario'] ?? '');
$input = (string) ($options['input'] ?? '');

try {
    if ($run === '' || $worker === '' || $scenario === '' || $input === '') {
        throw new RuntimeException('Invalid worker arguments.');
    }
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $kernel = $app->make(Kernel::class);
    $kernel->bootstrap();
    file_put_contents($run.'ready.'.$worker, '1', LOCK_EX);
    $deadline = microtime(true) + 30;
    while (! is_file($run.'start')) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Barrier timeout.');
        } usleep(10000);
    }
    $payload = json_decode((string) file_get_contents($input), true, 512, JSON_THROW_ON_ERROR);
    $started = microtime(true);
    $created = null;
    if ($scenario === 'api-key-quota') {
        $result = app(CreateApiKeyAction::class)->issue((string) $payload['user_id'], 'relational-worker', $payload['permissions'] ?? null, 60, null, null);
        $created = (string) $result->apiKey->getKey();
    } elseif ($scenario === 'webhook-endpoint-quota') {
        $user = User::findOrFail($payload['user_id']);
        $result = app(WebhookEndpointService::class)->create($user, [
            'name' => (string) ($payload['name'] ?? 'relational-worker'),
            'url' => (string) ($payload['url'] ?? 'https://example.com/webhooks/relational'),
            'events' => $payload['events'] ?? ['outbound.message.sent'],
            'is_active' => true,
        ]);
        $created = (string) $result['endpoint']->getKey();
    } elseif (in_array($scenario, ['inbox-user-quota', 'mail-server-capacity', 'anonymous-capacity'], true)) {
        $created = (string) app(CreateInboxAction::class)->execute(
            CreateInboxData::fromArray($payload),
            isset($payload['user_id']) ? User::findOrFail($payload['user_id']) : null,
            isset($payload['user_id'])
                ? InboxMutationContext::forApi((string) $payload['user_id'], (string) ($payload['api_key_id'] ?? '00000000-0000-4000-8000-000000000001'))
                : InboxMutationContext::forAnonymous()
        )->getKey();
    } else {
        throw new RuntimeException('Unsupported scenario.');
    }
    echo json_encode(['worker_id' => $worker, 'scenario' => $scenario, 'status' => 'success', 'exception' => null, 'created_id' => $created, 'duration_ms' => (int) ((microtime(true) - $started) * 1000)], JSON_THROW_ON_ERROR);
} catch (ApiKeyQuotaExceededException|InboxQuotaExceededException|EligibleMailServerUnavailableException $e) {
    echo json_encode(['worker_id' => $worker, 'scenario' => $scenario, 'status' => 'rejected', 'exception' => ['class' => $e::class], 'created_id' => null, 'duration_ms' => 0], JSON_THROW_ON_ERROR);
    exit(0);
} catch (OutboundSendException $e) {
    if ($e->errorCode === 'plan_limit_reached') {
        echo json_encode(['worker_id' => $worker, 'scenario' => $scenario, 'status' => 'rejected', 'exception' => ['class' => $e::class], 'created_id' => null, 'duration_ms' => 0], JSON_THROW_ON_ERROR);
        exit(0);
    }
    throw $e;
} catch (Throwable $e) {
    fwrite(STDERR, 'worker error: '.preg_replace('/(password|token|hash|secret|authorization)[^\n]*/i', '$1=[redacted]', $e->getMessage()));
    exit(1);
}
