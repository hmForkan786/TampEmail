<?php

declare(strict_types=1);

use App\Services\Outbound\OutboundWorkerConfigValidator;

beforeEach(function (): void {
    config([
        'queue.default' => 'redis',
        'queue.connections.redis.retry_after' => 120,
        'queue.connections.database.retry_after' => 90,
        'outbound.smtp.timeout' => 30,
        'outbound.worker.job_timeout_seconds' => 60,
    ]);
});

it('accepts the documented default ordering: 30s smtp < 60s job < 120s retry_after', function (): void {
    $result = app(OutboundWorkerConfigValidator::class)->validate();

    expect($result['valid'])->toBeTrue()
        ->and($result['failure_code'])->toBeNull()
        ->and($result['checks']['smtp_below_job_timeout'])->toBeTrue()
        ->and($result['checks']['job_timeout_below_retry_after'])->toBeTrue()
        ->and($result['checks']['retry_after_minimum'])->toBeTrue();
});

it('rejects when smtp timeout is not below the job timeout', function (): void {
    config(['outbound.worker.job_timeout_seconds' => 30]);

    $result = app(OutboundWorkerConfigValidator::class)->validate();

    expect($result['valid'])->toBeFalse()
        ->and($result['failure_code'])->toBe('smtp_timeout_not_below_job_timeout');
});

it('rejects when job timeout is not below the connection retry_after', function (): void {
    config(['queue.connections.redis.retry_after' => 60]);

    $result = app(OutboundWorkerConfigValidator::class)->validate();

    expect($result['valid'])->toBeFalse()
        ->and($result['failure_code'])->toBe('job_timeout_not_below_retry_after');
});

it('rejects retry_after below the 90 second minimum even if ordering holds', function (): void {
    config([
        'outbound.worker.job_timeout_seconds' => 10,
        'queue.connections.redis.retry_after' => 20,
        'outbound.smtp.timeout' => 5,
    ]);

    $result = app(OutboundWorkerConfigValidator::class)->validate();

    expect($result['valid'])->toBeFalse()
        ->and($result['failure_code'])->toBe('retry_after_below_minimum');
});

it('fails closed when the active queue connection has no retry_after configured', function (): void {
    config(['queue.default' => 'sync']);

    $result = app(OutboundWorkerConfigValidator::class)->validate();

    expect($result['valid'])->toBeFalse()
        ->and($result['failure_code'])->toBe('retry_after_unavailable');
});

it('never exposes secrets in the validation checks', function (): void {
    config(['outbound.smtp.password' => 'super-secret-password']);

    $result = app(OutboundWorkerConfigValidator::class)->validate();

    expect(json_encode($result))->not->toContain('super-secret-password');
});
