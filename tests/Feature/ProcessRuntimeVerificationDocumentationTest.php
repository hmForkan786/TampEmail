<?php

use Illuminate\Support\Facades\File;

function verificationArtifact(string $path): string
{
    expect(File::exists(base_path($path)))->toBeTrue();

    return preg_replace("~\r\n?~", "\n", File::get(base_path($path))) ?? '';
}

it('defines an isolated redis verification environment', function (): void {
    $env = verificationArtifact('.env.process-verification.example');

    foreach ([
        'APP_ENV=local',
        'QUEUE_CONNECTION=redis',
        'CACHE_STORE=redis',
        'QUEUE_DEFAULT=process-verification',
        'CACHE_PREFIX=temail_process_verification_<UNIQUE_NAMESPACE>',
        'REDIS_DB=<ISOLATED_REDIS_DATABASE_INDEX>',
        'APP_DEBUG=false',
    ] as $directive) {
        expect(str_contains($env, $directive))->toBeTrue();
    }

    expect($env)->not->toMatch('/(?:mysql|pgsql|redis):\/\/[^\s]+/i')
        ->and($env)->not->toMatch('/C:\\xampp/i')
        ->and($env)->not->toMatch('/(?:password|secret|token)\s*=\s*[^<\s][^\r\n]*/i')
        ->and($env)->not->toMatch('/(?:prod|production)[-_](?:db|redis|database)/i');
});

it('defines a local-only pinned redis compose service', function (): void {
    $compose = verificationArtifact('docker-compose.process-verification.yml');

    expect($compose)->toContain('temail-process-verification-redis')
        ->and($compose)->toMatch('/image:\s*redis:\d+\.\d+\.\d+-alpine/')
        ->and($compose)->toContain('127.0.0.1:6389:6379')
        ->and($compose)->toContain('healthcheck:')
        ->and($compose)->toContain('restart: "no"')
        ->and($compose)->toContain('temail-process-verification-network')
        ->and($compose)->not->toContain(':latest')
        ->and($compose)->not->toMatch('/volumes:\s*\n/i')
        ->and($compose)->not->toMatch('/(?:password|secret|token)\s*:/i')
        ->and($compose)->not->toMatch('/production/i');
});

it('documents the complete isolated runtime verification and cleanup flow', function (): void {
    $runbook = verificationArtifact('docs/PROCESS_RUNTIME_VERIFICATION.md');

    foreach ([
        'non-production verification',
        '.env.process-verification',
        'docker compose -f docker-compose.process-verification.yml up -d',
        'redis-cli ping',
        'optimize:clear',
        'config:cache',
        'route:cache',
        'event:cache',
        'isolated verification database',
        'exactly two workers',
        'exactly one scheduler strategy',
        'safe no-side-effect verification jobs',
        'processes:health --json',
        'Active workers: 2',
        'Scheduler: fresh',
        'Raw process identifiers: absent',
        'PROCESS_HEARTBEAT_TTL_SECONDS',
        'queue:failed',
        'stale',
        'TTL',
        'Stop both queue workers',
        'no verification PHP processes remain',
        'docker compose -f docker-compose.process-verification.yml down --remove-orphans',
        'git status --short',
        'Redis unavailable',
        'PHP Redis extension missing',
        'Stale Laravel config',
        'Queue reports `sync`',
        'Cache reports `file`',
        'Scheduler heartbeat missing',
        'Lock unsupported',
        'Worker registry count incorrect',
    ] as $requirement) {
        expect(str_contains($runbook, $requirement))->toBeTrue();
    }
});

it('documents destructive commands only as prohibited safety warnings', function (): void {
    $content = implode("\n", [
        verificationArtifact('.env.process-verification.example'),
        verificationArtifact('docker-compose.process-verification.yml'),
        verificationArtifact('docs/PROCESS_RUNTIME_VERIFICATION.md'),
    ]);

    expect($content)->not->toMatch('/^\s*(?:docker\s+[^\n]*\s+)?FLUSHALL\b/im')
        ->and($content)->not->toMatch('/^\s*(?:docker\s+[^\n]*\s+)?FLUSHDB\b/im')
        ->and($content)->not->toMatch('/(?:redis|mysql|pgsql):\/\/[^\s`]+/i')
        ->and($content)->not->toMatch('/-----BEGIN [A-Z ]+ PRIVATE KEY-----/')
        ->and($content)->not->toMatch('/C:\\xampp/i')
        ->and($content)->not->toMatch('/(?:password|secret|token)\s*[:=]\s*[^<\s][^\r\n]*/i')
        ->and($content)->not->toMatch('/^\s*(?:docker\s+[^\n]*\s+)?(?:queue:clear|cache:clear|queue:flush|FLUSHALL|FLUSHDB)\b/im');
});

it('reads artifacts without changing application configuration', function (): void {
    $before = config('queue.default');

    verificationArtifact('.env.process-verification.example');
    verificationArtifact('docker-compose.process-verification.yml');
    verificationArtifact('docs/PROCESS_RUNTIME_VERIFICATION.md');

    expect(config('queue.default'))->toBe($before);
});

it('documents the native and WSL Redis alternative safely', function (): void {
    $env = verificationArtifact('.env.process-verification.example');
    $runbook = verificationArtifact('docs/PROCESS_RUNTIME_VERIFICATION.md');

    foreach ([
        'Native/WSL Redis Verification Path',
        'Docker remains the preferred',
        'native Redis service is an accepted alternative',
        '127.0.0.1',
        'redis-cli -h 127.0.0.1 -p 6379 ping',
        'wsl redis-cli -h 127.0.0.1 -p 6379 ping',
        'one verified endpoint',
        'unique numeric Redis DB indexes',
        'unique queue name',
        'unique cache prefix',
        'run preflight before migrations, workers, or scheduler',
        'exactly two workers',
        'exactly one scheduler strategy',
        'stop every process',
        'restore the original environment',
        'global wipe',
        'unconditional database wipe',
    ] as $requirement) {
        expect(str_contains($runbook, $requirement))->toBeTrue();
    }

    expect($env)->toContain('REDIS_PORT=6389')
        ->and($env)->toContain('REDIS_PORT=6379')
        ->and($runbook)->not->toContain('FLUSHALL')
        ->and($runbook)->not->toContain('FLUSHDB')
        ->and($runbook)->not->toMatch('/C:\\xampp/i')
        ->and($runbook)->not->toMatch('/(?:redis|mysql|pgsql):\/\/[^\s`]+/i')
        ->and($runbook)->not->toMatch('/(?:password|secret|token)\s*[:=]\s*[^<\s][^\r\n]*/i');
});
