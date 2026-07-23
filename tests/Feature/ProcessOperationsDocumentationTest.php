<?php

use Illuminate\Support\Facades\File;

function operationsDocument(string $relativePath): string
{
    $path = base_path($relativePath);
    expect(File::exists($path))->toBeTrue();

    return File::get($path);
}

it('provides the worker supervisor contract', function (): void {
    $worker = operationsDocument('deploy/supervisor/temail-worker.conf.example');

    foreach (['artisan queue:work', 'directory=<PROJECT_PATH>', 'user=<DEPLOYMENT_USER>', 'autostart=true', 'autorestart=true', 'stopsignal=TERM', 'stopwaitsecs=<GRACEFUL_STOP_WAIT_SECONDS>', '--queue=<QUEUE_NAMES>', '--sleep=<SLEEP_SECONDS>', '--tries=<MAX_TRIES>', '--timeout=<JOB_TIMEOUT_SECONDS>', '--backoff=<BACKOFF_SECONDS>', 'numprocs=<WORKER_COUNT>'] as $directive) {
        expect(str_contains($worker, $directive))->toBeTrue();
    }
});

it('provides the scheduler supervisor contract', function (): void {
    $scheduler = operationsDocument('deploy/supervisor/temail-scheduler.conf.example');

    foreach (['artisan schedule:work', 'directory=<PROJECT_PATH>', 'user=<DEPLOYMENT_USER>', 'autostart=true', 'autorestart=true', 'stopsignal=TERM', 'stopwaitsecs=<GRACEFUL_STOP_WAIT_SECONDS>', 'numprocs=1'] as $directive) {
        expect(str_contains($scheduler, $directive))->toBeTrue();
    }
});

it('documents the operational deployment contract', function (): void {
    $runbook = operationsDocument('docs/PROCESS_OPERATIONS.md');

    expect($runbook)->toContain('schedule:run')
        ->toContain('schedule:work')
        ->toContain('Do not start both')
        ->toContain('durable queue connection')
        ->toContain('shared, atomic cache/lock store')
        ->toContain('queue:restart')
        ->toContain('Deployment and restart order')
        ->toContain('Graceful stop and rollback')
        ->toContain('processes:health --json')
        ->toContain('queue:failed')
        ->toContain('queue:retry')
        ->toContain('Multi-instance considerations')
        ->toContain('heartbeat')
        ->toContain('config:cache')
        ->toContain('route:cache')
        ->toContain('event:cache')
        ->toContain('timezone')
        ->toContain('Log rotation');
});

it('keeps deployment examples environment-neutral and free of sensitive values', function (): void {
    $content = implode("\n", [
        operationsDocument('deploy/supervisor/temail-worker.conf.example'),
        operationsDocument('deploy/supervisor/temail-scheduler.conf.example'),
        operationsDocument('docs/PROCESS_OPERATIONS.md'),
    ]);

    expect($content)->not->toContain('C:\\xampp')
        ->and($content)->not->toMatch('/password\s*=/i')
        ->and($content)->not->toMatch('/(?:redis|mysql|pgsql):\/\/[^\s`]+/i')
        ->and($content)->not->toMatch('/-----BEGIN [A-Z ]+ PRIVATE KEY-----/')
        ->and($content)->not->toMatch('/\b(?:sk|pk|token)[-_][A-Za-z0-9]{12,}\b/i')
        ->and($content)->not->toContain('localhost:6379')
        ->and($content)->toContain('<PROJECT_PATH>')
        ->and($content)->toContain('<PHP_BINARY>')
        ->and($content)->toContain('<DEPLOYMENT_USER>')
        ->and($content)->toContain('<LOG_DIRECTORY>');
});

it('does not mutate application runtime state while reading documentation', function (): void {
    $before = config('queue.default');

    operationsDocument('deploy/supervisor/temail-worker.conf.example');
    operationsDocument('deploy/supervisor/temail-scheduler.conf.example');
    operationsDocument('docs/PROCESS_OPERATIONS.md');

    expect(config('queue.default'))->toBe($before);
});
