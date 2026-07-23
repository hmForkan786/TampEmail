<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

final class RelationalConcurrencyHarness
{
    public const SCENARIOS = ['api-key-quota', 'inbox-user-quota', 'mail-server-capacity', 'anonymous-capacity'];

    /** @param array<string, array<string, mixed>> $workers */
    public static function run(string $scenario, array $workers, int $timeoutSeconds = 30): array
    {
        self::guard($scenario, $timeoutSeconds);
        $run = self::runDirectory();
        $manifest = $run.'manifest.json';
        file_put_contents($manifest, json_encode(['scenario' => $scenario, 'workers' => array_keys($workers)], JSON_THROW_ON_ERROR), LOCK_EX);
        $processes = [];
        $pipes = [];

        try {
            foreach ($workers as $workerId => $payload) {
                $input = $run.$workerId.'.json';
                file_put_contents($input, json_encode($payload, JSON_THROW_ON_ERROR), LOCK_EX);
                $command = [PHP_BINARY, base_path('tests/Support/relational_worker.php'), '--run='.$run, '--worker='.$workerId, '--scenario='.$scenario, '--input='.$input];
                $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
                $process = proc_open($command, $descriptors, $workerPipes, base_path(), self::safeEnvironment());
                if (! is_resource($process)) {
                    throw new RuntimeException('Unable to start relational worker.');
                }
                $processes[$workerId] = $process;
                $pipes[$workerId] = $workerPipes;
            }

            self::waitForFiles($run, array_map(fn (string $id): string => 'ready.'.$id, array_keys($workers)), $timeoutSeconds);
            file_put_contents($run.'start', '1', LOCK_EX);
            $results = [];
            $deadline = microtime(true) + $timeoutSeconds;
            foreach ($processes as $workerId => $process) {
                while (true) {
                    $status = proc_get_status($process);
                    if (! $status['running']) {
                        break;
                    }
                    if (microtime(true) > $deadline) {
                        throw new RuntimeException("Relational worker {$workerId} timed out.");
                    }
                    usleep(10000);
                }
                $stdout = stream_get_contents($pipes[$workerId][1]);
                $stderr = stream_get_contents($pipes[$workerId][2]);
                fclose($pipes[$workerId][1]);
                fclose($pipes[$workerId][2]);
                $exit = proc_close($process);
                unset($processes[$workerId], $pipes[$workerId]);
                if ($exit !== 0) {
                    throw new RuntimeException("Relational worker {$workerId} failed: ".self::safeError((string) $stderr));
                }
                $result = json_decode(trim((string) $stdout), true);
                if (! is_array($result) || ($result['worker_id'] ?? null) !== (string) $workerId) {
                    throw new RuntimeException("Malformed result from worker {$workerId}.");
                }
                self::assertSafeWorkerPayload($result);
                $results[] = $result;
            }

            $summary = self::summary($scenario, $results);
            self::persistSummaryArtifact($summary, $results);

            return $summary;
        } finally {
            foreach ($processes as $process) {
                if (is_resource($process)) {
                    @proc_terminate($process);
                    @proc_close($process);
                }
            }
            foreach ($pipes as $workerPipes) {
                foreach ($workerPipes as $pipe) {
                    if (is_resource($pipe)) {
                        @fclose($pipe);
                    }
                }
            }
            self::removeDirectory($run);
        }
    }

    public static function guard(string $scenario, int $timeoutSeconds): void
    {
        if (! in_array($scenario, self::SCENARIOS, true)) {
            throw new RuntimeException('Unknown relational scenario.');
        }
        if ($timeoutSeconds < 1 || ! function_exists('proc_open')) {
            throw new RuntimeException('Process API unavailable.');
        }
        if (! in_array((string) config('database.default'), ['mysql', 'pgsql'], true)) {
            throw new RuntimeException('Relational harness requires MySQL or PostgreSQL.');
        }
        if (env('RUN_RELATIONAL_TESTS') !== '1') {
            throw new RuntimeException('RUN_RELATIONAL_TESTS=1 is required.');
        }
        if (preg_match('/(^|_)(prod|production)($|_)/i', (string) config('database.connections.'.config('database.default').'.database'))) {
            throw new RuntimeException('Production database target rejected.');
        }
    }

    /** @param list<array<string, mixed>> $results */
    public static function summary(string $scenario, array $results): array
    {
        $summary = [
            'scenario' => $scenario,
            'workers' => count($results),
            'successes' => 0,
            'rejections' => 0,
            'errors' => 0,
            'rejection_classes' => [],
            'final_count' => null,
            'assertion' => 'UNVERIFIED',
        ];
        foreach ($results as $result) {
            if (($result['status'] ?? null) === 'success') {
                $summary['successes']++;
            } elseif (($result['status'] ?? null) === 'rejected') {
                $summary['rejections']++;
                $class = $result['exception']['class'] ?? null;
                if (is_string($class) && $class !== '') {
                    $summary['rejection_classes'][] = $class;
                }
            } else {
                $summary['errors']++;
            }
        }
        if ($summary['successes'] === 1 && $summary['rejections'] === 1 && $summary['errors'] === 0) {
            $summary['assertion'] = 'PASS';
        }

        return $summary;
    }

    private static function runDirectory(): string
    {
        $dir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'relational-'.bin2hex(random_bytes(8)).DIRECTORY_SEPARATOR;
        if (! mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new RuntimeException('Unable to create barrier directory.');
        }

        return $dir;
    }

    /** @param list<string> $files */
    private static function waitForFiles(string $dir, array $files, int $timeout): void
    {
        $deadline = microtime(true) + $timeout;
        do {
            if (count(array_filter($files, fn (string $file): bool => is_file($dir.$file))) === count($files)) {
                return;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);

        throw new RuntimeException('Relational worker barrier timed out.');
    }

    /** @return array<string, string> */
    private static function safeEnvironment(): array
    {
        // proc_open() replaces the child environment when an array is supplied.
        // Use getenv() so CI-provided DB_HOST, DB_PORT, and credentials are
        // preserved even when PHP's variables_order does not populate $_ENV.
        $environment = getenv();
        if (! is_array($environment)) {
            $environment = [];
        }
        $environment['APP_ENV'] = 'testing';
        $environment['RUN_RELATIONAL_TESTS'] = '1';
        $environment['DB_CONNECTION'] = (string) env('DB_CONNECTION');
        $environment['PUBLIC_MAIL_SERVER_POOL'] = (string) config('inbox.public_mail_server_pool', '');
        if (isset($environment['APP_ENV']) && preg_match('/prod/i', (string) $environment['APP_ENV'])) {
            throw new RuntimeException('Production environment rejected.');
        }

        return $environment;
    }

    private static function safeError(string $error): string
    {
        return preg_replace('/(password|token|hash|secret|authorization)[^\n]*/i', '$1=[redacted]', $error) ?: 'worker failure';
    }

    /** @param array<string, mixed> $result */
    private static function assertSafeWorkerPayload(array $result): void
    {
        $encoded = strtolower(json_encode($result, JSON_THROW_ON_ERROR));
        foreach (['"token"', '"hash"', 'password', 'secret', 'authorization'] as $needle) {
            if (str_contains($encoded, $needle)) {
                throw new RuntimeException('Worker result contained a forbidden sensitive marker.');
            }
        }
        if (isset($result['created_id']) && is_string($result['created_id']) && str_contains($result['created_id'], '@')) {
            throw new RuntimeException('Worker result must not include mailbox addresses.');
        }
    }

    /**
     * @param array<string, mixed> $summary
     * @param list<array<string, mixed>> $results
     */
    private static function persistSummaryArtifact(array $summary, array $results): void
    {
        $dir = storage_path('test-results/relational');
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            return;
        }
        $safeResults = array_map(static function (array $result): array {
            return [
                'worker_id' => $result['worker_id'] ?? null,
                'scenario' => $result['scenario'] ?? null,
                'status' => $result['status'] ?? null,
                'exception' => $result['exception'] ?? null,
                'created_id' => $result['created_id'] ?? null,
                'duration_ms' => $result['duration_ms'] ?? null,
            ];
        }, $results);
        $payload = [
            'summary' => $summary,
            'workers' => $safeResults,
            'driver' => (string) config('database.default'),
        ];
        $path = $dir.DIRECTORY_SEPARATOR.$summary['scenario'].'-'.bin2hex(random_bytes(4)).'.json';
        file_put_contents($path, json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT), LOCK_EX);
    }

    private static function removeDirectory(string $dir): void
    {
        foreach (glob($dir.'*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    }
}
