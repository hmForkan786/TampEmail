<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Throwable;

final class ProcessVerificationPreflight extends Command
{
    protected $signature = 'processes:verification-preflight {--json}';
    protected $description = 'Validate the isolated process-verification environment before runtime startup.';

    public function handle(): int
    {
        try {
            [$checks, $errors] = $this->validateEnvironment();
            $payload = ['status' => $errors === [] ? 'passed' : 'failed', 'redis_mode' => $this->redisMode(), 'checks' => $checks, 'errors' => $errors];

            if ($this->option('json')) {
                $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            } else {
                $this->line('Process verification preflight: '.($errors === [] ? 'PASS' : 'FAIL'));
                $this->line('Queue: '.($checks['queue'] === 'passed' ? 'redis' : 'failed'));
                $this->line('Cache: '.($checks['cache'] === 'passed' ? 'redis' : 'failed'));
                $this->line('Redis verification mode: '.$this->redisMode());
                $this->line('Database isolation: '.($checks['database'] === 'passed' ? 'confirmed' : 'failed'));
                $this->line('Redis isolation: '.($checks['redis'] === 'passed' ? 'confirmed' : 'failed'));
                $this->line('Placeholders: '.($checks['placeholders'] === 'passed' ? 'none' : 'detected'));
                $this->line('Git tracking: '.($checks['git_tracking'] === 'passed' ? 'safe' : 'failed'));
                foreach ($errors as $error) $this->line('- '.$error);
            }

            return $errors === [] ? self::SUCCESS : self::FAILURE;
        } catch (Throwable) {
            $payload = ['status' => 'failed', 'checks' => ['environment' => 'failed'], 'errors' => ['preflight_unavailable']];
            if ($this->option('json')) $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            else $this->line("Process verification preflight: FAIL\n- preflight_unavailable");

            return 2;
        }
    }

    /** @return array{0: array<string, string>, 1: list<string>} */
    private function validateEnvironment(): array
    {
        $env = [
            'environment' => $this->value('app.env'),
            'app_name' => $this->value('app.name'),
            'debug' => config('app.debug'),
            'queue' => $this->value('queue.default'),
            'cache' => $this->value('cache.default'),
            'redis_host' => $this->value('database.redis.default.host'),
            'redis_port' => $this->value('database.redis.default.port'),
            'redis_db' => $this->value('database.redis.default.database'),
            'redis_cache_db' => $this->value('database.redis.cache.database'),
            'redis_queue_db' => $this->value('database.redis.queue.database'),
            'queue_name' => $this->value('queue.connections.redis.queue'),
            'cache_prefix' => $this->value('cache.prefix'),
            'database' => $this->value('database.connections.'.config('database.default').'.database'),
            'database_connection' => config('database.default'),
            'app_key' => $this->value('app.key'),
            'ttl' => config('processes.heartbeat_ttl_seconds'),
            'write_interval' => config('processes.heartbeat_write_interval_seconds'),
            'worker_count' => config('processes.worker_count'),
        ];

        $placeholder = fn (mixed $value): bool => $value === null || (is_string($value) && (trim($value) === '' || preg_match('/<[^>]+>|CHANGE_ME|REPLACE_ME|TODO/i', $value) === 1));
        $errors = [];
        $placeholderFields = ['environment','queue','cache','redis_host','redis_port','redis_db','redis_cache_db','redis_queue_db','queue_name','cache_prefix','database','app_key'];
        foreach ($placeholderFields as $field) if ($placeholder($env[$field])) $errors[] = $field.' is still a placeholder or empty';
        if ($env['environment'] === 'production') $errors[] = 'APP_ENV must not be production';
        if ($env['debug'] !== false && $env['debug'] !== '0') $errors[] = 'APP_DEBUG must be false';
        if ($env['queue'] !== 'redis') $errors[] = 'Queue driver must be redis';
        if ($env['cache'] !== 'redis') $errors[] = 'Cache store must be redis';
        if (! str_contains(strtolower((string) $env['app_name']), 'verification')) $errors[] = 'Application name must identify the verification profile';
        if (! in_array(strtolower((string) $env['redis_host']), ['127.0.0.1', 'localhost', '::1'], true) && ! str_contains(strtolower((string) $env['redis_host']), 'verification')) $errors[] = 'Redis host is not isolated';
        if (! is_numeric($env['redis_port']) || (int) $env['redis_port'] <= 0 || ! in_array((int) $env['redis_port'], [6389, 6379], true)) $errors[] = 'Redis port must be an approved verification port (6389 or 6379)';
        foreach (['redis_db','redis_cache_db','redis_queue_db'] as $field) if (! is_numeric($env[$field]) || (int) $env[$field] < 0) $errors[] = $field.' must be a numeric isolated index';
        if (! str_contains(strtolower((string) $env['queue_name']), 'verification')) $errors[] = 'Queue name must be verification-specific';
        if (! str_contains(strtolower((string) $env['cache_prefix']), 'verification')) $errors[] = 'Cache prefix must be verification-specific';
        if (preg_match('/(?:production|prod|live|temail|^default$)/i', (string) $env['database'])) $errors[] = 'Database name looks production-like';
        if (! in_array(strtolower((string) $env['database_connection']), ['testing', 'sqlite', 'process_verification'], true) && ! str_contains(strtolower((string) $env['database_connection']), 'verification')) $errors[] = 'Database connection is not isolated';
        if (! is_numeric($env['ttl']) || (int) $env['ttl'] <= 0 || ! is_numeric($env['write_interval']) || (int) $env['write_interval'] <= 0) $errors[] = 'Heartbeat thresholds must be positive integers';
        elseif ((int) $env['ttl'] <= (int) $env['write_interval']) $errors[] = 'Heartbeat TTL must exceed write interval';
        if (! is_int($env['ttl']) && ! (is_string($env['ttl']) && ctype_digit($env['ttl']))) $errors[] = 'Scheduler heartbeat threshold must be an integer';
        elseif ((int) $env['ttl'] <= 0) $errors[] = 'Scheduler heartbeat threshold must be positive';
        elseif ((int) $env['ttl'] <= 60) $errors[] = 'Scheduler heartbeat threshold must exceed the one-minute cadence';
        if ((int) $env['worker_count'] < 2) $errors[] = 'Worker count must be at least two';
        $tracking = $this->trackedVerificationEnv();
        if ($tracking === null) throw new \RuntimeException('Git tracking check unavailable.');
        if ($tracking) $errors[] = 'Verification environment file must not be tracked';

        $status = fn (array $fields): string => count(array_intersect($fields, $errors)) === 0 ? 'passed' : 'failed';
        $checks = [
            'environment' => $status(['environment must not be production', 'APP_DEBUG must be false']),
            'queue' => $status(['Queue driver must be redis', 'Queue name must be verification-specific']),
            'cache' => $status(['Cache store must be redis', 'Cache prefix must be verification-specific']),
            'redis' => $status(['Redis host is not isolated', 'Redis port must be an approved verification port (6389 or 6379)']),
            'database' => $status(['Database name looks production-like', 'Database connection is not isolated']),
            'placeholders' => count(array_filter($errors, fn (string $e): bool => str_contains($e, 'placeholder') || str_contains($e, 'empty'))) === 0 ? 'passed' : 'failed',
            'git_tracking' => $tracking ? 'failed' : 'passed',
        ];
        return [$checks, $errors];
    }

    private function trackedVerificationEnv(): ?bool
    {
        try {
            $result = Process::run(['git', 'ls-files', '--error-unmatch', '.env.process-verification']);
            return $result->successful();
        } catch (Throwable) {
            return null;
        }
    }

    private function value(string $key): mixed { return config($key); }

    private function redisMode(): string
    {
        return (int) config('database.redis.default.port') === 6379 ? 'native' : 'docker';
    }
}
