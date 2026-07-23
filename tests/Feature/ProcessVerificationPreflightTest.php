<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

function validVerificationConfig(): void
{
    config([
        'app.env' => 'local',
        'app.debug' => false,
        'app.key' => 'base64:'.str_repeat('a', 43),
        'app.name' => 'TEmail Process Verification',
        'queue.default' => 'redis',
        'queue.connections.redis.queue' => 'process-verification',
        'cache.default' => 'redis',
        'cache.prefix' => 'temail_process_verification_unique',
        'database.default' => 'sqlite',
        'database.connections.sqlite.database' => 'process_verification_test',
        'database.redis.default.host' => '127.0.0.1',
        'database.redis.default.port' => 6389,
        'database.redis.default.database' => 3,
        'database.redis.cache.database' => 3,
        'database.redis.queue.database' => 3,
        'processes.worker_count' => 2,
        'processes.heartbeat_ttl_seconds' => 180,
        'processes.heartbeat_write_interval_seconds' => 30,
    ]);
}

it('passes a complete isolated verification configuration', function (): void {
    validVerificationConfig();

    expect(Artisan::call('processes:verification-preflight', ['--json' => true]))->toBe(0);
    $json = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($json['status'])->toBe('passed')
        ->and($json['redis_mode'])->toBe('docker')
        ->and($json['errors'])->toBe([])
        ->and($json['checks'])->toMatchArray(['queue' => 'passed', 'cache' => 'passed', 'redis' => 'passed', 'database' => 'passed', 'placeholders' => 'passed', 'git_tracking' => 'passed'])
        ->and(Artisan::output())->not->toContain('base64:')
        ->and(Artisan::output())->not->toContain('process_verification_test');
});

it('fails closed for production, unsafe drivers, placeholders, and thresholds', function (): void {
    validVerificationConfig();
    config([
        'app.env' => 'production',
        'app.debug' => true,
        'app.key' => '<GENERATE_A_LOCAL_VERIFICATION_KEY>',
        'queue.default' => 'sync',
        'cache.default' => 'file',
        'database.connections.sqlite.database' => 'production_database',
        'database.redis.cache.database' => '<ISOLATED_REDIS_DATABASE_INDEX>',
        'queue.connections.redis.queue' => 'default',
        'cache.prefix' => 'laravel-cache',
        'processes.heartbeat_ttl_seconds' => 10,
        'processes.heartbeat_write_interval_seconds' => 10,
    ]);

    expect(Artisan::call('processes:verification-preflight', ['--json' => true]))->toBe(1);
    $output = Artisan::output();
    $json = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
    expect($json['status'])->toBe('failed')
        ->and($json['errors'])->toContain('APP_ENV must not be production', 'Queue driver must be redis', 'Cache store must be redis', 'Heartbeat TTL must exceed write interval')
        ->and($output)->not->toContain('<GENERATE_A_LOCAL_VERIFICATION_KEY>')
        ->and($output)->not->toContain('production_database');
});

it('renders safe human failure output without mutating config', function (): void {
    validVerificationConfig();
    config(['app.key' => 'CHANGE_ME', 'database.redis.default.port' => 6379]);
    $before = config('queue.default');

    $exitCode = Artisan::call('processes:verification-preflight');
    $output = Artisan::output();
    expect($exitCode)->toBe(1);
    expect(str_contains($output, 'Process verification preflight: FAIL'))->toBeTrue()
        ->and(str_contains($output, 'app_key is still a placeholder'))->toBeTrue()
        ->and(str_contains($output, 'base64:'))->toBeFalse()
        ->and(config('queue.default'))->toBe($before);
});

it('returns internal failure code when git execution is unavailable', function (): void {
    validVerificationConfig();
    Process::fake(function (): never { throw new RuntimeException('git unavailable with secret=hidden'); });

    expect(Artisan::call('processes:verification-preflight', ['--json' => true]))->toBe(2);
    expect(Artisan::output())->toContain('preflight_unavailable')->not->toContain('secret=hidden');
});

it('rejects each unsafe environment or driver branch independently', function (): void {
    $cases = [
        'production env' => ['app.env' => 'production'],
        'debug enabled' => ['app.debug' => true],
        'sync queue' => ['queue.default' => 'sync'],
        'non redis queue' => ['queue.default' => 'database'],
        'file cache' => ['cache.default' => 'file'],
        'non redis cache' => ['cache.default' => 'array'],
        'placeholder key' => ['app.key' => '<APP_KEY>'],
        'empty key' => ['app.key' => ''],
        'placeholder queue db' => ['database.redis.queue.database' => '<DB>'],
        'placeholder cache db' => ['database.redis.cache.database' => '<DB>'],
        'missing redis db' => ['database.redis.default.database' => null],
        'invalid port' => ['database.redis.default.port' => 6378],
        'foreign redis host' => ['database.redis.default.host' => 'redis.production.internal'],
        'empty queue' => ['queue.connections.redis.queue' => ''],
        'generic queue' => ['queue.connections.redis.queue' => 'default'],
        'empty prefix' => ['cache.prefix' => ''],
        'generic prefix' => ['cache.prefix' => 'laravel-cache'],
        'placeholder database' => ['database.connections.sqlite.database' => '<DATABASE>'],
        'production database' => ['database.connections.sqlite.database' => 'production_data'],
        'missing write interval' => ['processes.heartbeat_write_interval_seconds' => null],
        'zero write interval' => ['processes.heartbeat_write_interval_seconds' => 0],
        'negative write interval' => ['processes.heartbeat_write_interval_seconds' => -1],
        'non integer write interval' => ['processes.heartbeat_write_interval_seconds' => 'abc'],
        'missing ttl' => ['processes.heartbeat_ttl_seconds' => null],
        'zero ttl' => ['processes.heartbeat_ttl_seconds' => 0],
        'negative ttl' => ['processes.heartbeat_ttl_seconds' => -1],
        'non integer ttl' => ['processes.heartbeat_ttl_seconds' => 'abc'],
        'equal ttl' => ['processes.heartbeat_ttl_seconds' => 30],
        'lower ttl' => ['processes.heartbeat_ttl_seconds' => 20],
        'one worker' => ['processes.worker_count' => 1],
    ];

    foreach ($cases as $label => $overrides) {
        validVerificationConfig();
        config($overrides);
        expect(Artisan::call('processes:verification-preflight', ['--json' => true]))->toBe(1, $label);
        $json = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        expect($json['status'], $label)->toBe('failed');
    }
});

it('rejects all generic queue names and cache prefixes', function (): void {
    foreach (['default', 'production', 'prod', 'live', 'temail'] as $name) {
        validVerificationConfig();
        config(['queue.connections.redis.queue' => $name]);
        expect(Artisan::call('processes:verification-preflight', ['--json' => true]))->toBe(1, $name);
    }

    foreach (['default', 'laravel-cache', 'production-cache', 'live-cache', 'temail-cache'] as $prefix) {
        validVerificationConfig();
        config(['cache.prefix' => $prefix]);
        expect(Artisan::call('processes:verification-preflight', ['--json' => true]))->toBe(1, $prefix);
    }
});

it('checks tracked and untracked verification environment states through the git seam', function (): void {
    validVerificationConfig();
    Process::fake(fn () => Process::result('tracked', '', 0));
    expect(Artisan::call('processes:verification-preflight', ['--json' => true]))->toBe(1);
    expect(str_contains(Artisan::output(), 'Verification environment file must not be tracked'))->toBeTrue();

    validVerificationConfig();
    Process::fake(fn () => Process::result('', '', 1));
    expect(Artisan::call('processes:verification-preflight', ['--json' => true]))->toBe(0);
    $json = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($json['checks']['git_tracking'])->toBe('passed');
});

it('keeps successful JSON schema exact, safe, and mutation-free', function (): void {
    validVerificationConfig();
    $before = [config('queue.default'), config('cache.prefix'), config('app.key')];

    expect(Artisan::call('processes:verification-preflight', ['--json' => true]))->toBe(0);
    $output = Artisan::output();
    $json = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
    expect(array_keys($json))->toBe(['status', 'redis_mode', 'checks', 'errors'])
        ->and($json['status'])->toBe('passed')
        ->and($json['redis_mode'])->toBe('docker')
        ->and(array_keys($json['checks']))->toBe(['environment', 'queue', 'cache', 'redis', 'database', 'placeholders', 'git_tracking'])
        ->and($json['errors'])->toBe([])
        ->and($output)->not->toMatch('/(?:base64:|password|secret|token|redis:\/\/|[A-Z]:\\\\)/i')
        ->and([config('queue.default'), config('cache.prefix'), config('app.key')])->toBe($before);
});

it('accepts both approved loopback ports and reports only their safe modes', function (): void {
    foreach ([[6389, 'docker'], [6379, 'native']] as [$port, $mode]) {
        validVerificationConfig();
        config(['database.redis.default.port' => $port]);
        expect(Artisan::call('processes:verification-preflight', ['--json' => true]))->toBe(0, $mode);
        $output = Artisan::output();
        $json = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        expect($json['redis_mode'], $mode)->toBe($mode)
            ->and($output)->not->toContain('127.0.0.1')
            ->and($output)->not->toContain('6389')
            ->and($output)->not->toContain('6379');
    }

    foreach ([6378, 6380, 16379, '', 'abc', -1] as $port) {
        validVerificationConfig();
        config(['database.redis.default.port' => $port]);
        expect(Artisan::call('processes:verification-preflight', ['--json' => true]))->toBe(1, (string) $port);
    }

    validVerificationConfig();
    config(['database.redis.default.host' => 'redis.shared.internal', 'database.redis.default.port' => 6379]);
    expect(Artisan::call('processes:verification-preflight', ['--json' => true]))->toBe(1);
});

it('validates scheduler cadence using the existing heartbeat TTL configuration', function (): void {
    foreach ([
        'missing' => null,
        'zero' => 0,
        'negative' => -1,
        'non integer' => 'invalid',
        'too short for cadence' => 60,
    ] as $label => $threshold) {
        validVerificationConfig();
        config(['processes.heartbeat_ttl_seconds' => $threshold]);
        expect(Artisan::call('processes:verification-preflight', ['--json' => true]))->toBe(1, $label);
        $json = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        expect($json['status'], $label)->toBe('failed');
    }

    validVerificationConfig();
    expect(Artisan::call('processes:verification-preflight', ['--json' => true]))->toBe(0);
});

it('validates the database connection name separately from database name isolation', function (): void {
    foreach (['testing', 'sqlite', 'process_verification', 'local_verification'] as $connection) {
        validVerificationConfig();
        config(['database.default' => $connection, 'database.connections.'.$connection.'.database' => 'process_verification_test']);
        expect(Artisan::call('processes:verification-preflight', ['--json' => true]))->toBe(0, $connection);
    }

    foreach (['mysql', 'production', 'prod', 'live', 'default'] as $connection) {
        validVerificationConfig();
        config(['database.default' => $connection, 'database.connections.'.$connection.'.database' => 'process_verification_test']);
        expect(Artisan::call('processes:verification-preflight', ['--json' => true]))->toBe(1, $connection);
    }

    validVerificationConfig();
    config(['database.connections.sqlite.database' => 'production_database']);
    expect(Artisan::call('processes:verification-preflight', ['--json' => true]))->toBe(1);
});
