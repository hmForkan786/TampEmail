<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

$options = getopt('', ['run:', 'worker:', 'scenario:', 'input:']);
$run = (string) ($options['run'] ?? '');
$worker = (string) ($options['worker'] ?? '');
$scenario = (string) ($options['scenario'] ?? '');
$input = (string) ($options['input'] ?? '');

try {
    if ($run === '' || $worker === '' || $scenario === '' || $input === '') throw new RuntimeException('Invalid worker arguments.');
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
    $kernel->bootstrap();
    file_put_contents($run.'ready.'.$worker, '1', LOCK_EX);
    $deadline = microtime(true) + 30;
    while (! is_file($run.'start')) { if (microtime(true) > $deadline) throw new RuntimeException('Barrier timeout.'); usleep(10000); }
    $payload = json_decode((string) file_get_contents($input), true, 512, JSON_THROW_ON_ERROR);
    $started = microtime(true);
    $created = null;
    if ($scenario === 'api-key-quota') {
        $result = app(App\Actions\ApiKey\CreateApiKeyAction::class)->issue((string) $payload['user_id'], 'relational-worker', $payload['permissions'] ?? null, 60, null, null);
        $created = (string) $result->apiKey->getKey();
    } elseif (in_array($scenario, ['inbox-user-quota', 'mail-server-capacity', 'anonymous-capacity'], true)) {
        $created = (string) app(App\Actions\Inbox\CreateInboxAction::class)->execute(
            App\DTOs\Inbox\CreateInboxData::fromArray($payload),
            isset($payload['user_id']) ? App\Models\User::findOrFail($payload['user_id']) : null,
            isset($payload['user_id'])
                ? App\DTOs\Inbox\InboxMutationContext::forApi((string) $payload['user_id'], (string) ($payload['api_key_id'] ?? '00000000-0000-4000-8000-000000000001'))
                : App\DTOs\Inbox\InboxMutationContext::forAnonymous()
        )->getKey();
    } else throw new RuntimeException('Unsupported scenario.');
    echo json_encode(['worker_id' => $worker, 'scenario' => $scenario, 'status' => 'success', 'exception' => null, 'created_id' => $created, 'duration_ms' => (int) ((microtime(true) - $started) * 1000)], JSON_THROW_ON_ERROR);
} catch (App\Exceptions\ApiKeyQuotaExceededException|App\Exceptions\InboxQuotaExceededException|App\Exceptions\EligibleMailServerUnavailableException $e) {
    echo json_encode(['worker_id' => $worker, 'scenario' => $scenario, 'status' => 'rejected', 'exception' => ['class' => $e::class], 'created_id' => null, 'duration_ms' => 0], JSON_THROW_ON_ERROR);
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'worker error: '.preg_replace('/(password|token|hash|secret|authorization)[^\n]*/i', '$1=[redacted]', $e->getMessage()));
    exit(1);
}
