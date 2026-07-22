<?php

use App\Services\Audit\AuditLogWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('enforces sanitization at the audit writer boundary', function (): void {
    $log = app(AuditLogWriter::class)->write(
        action: 'test.redaction',
        oldValues: ['password' => 'old-secret', 'status' => 'active'],
        newValues: ['apiToken' => 'plain-token', 'status' => 'suspended'],
        metadata: ['api_key_id' => 'key-123', 'Authorization' => 'Bearer token'],
    );

    $stored = json_encode([$log->old_values, $log->new_values, $log->metadata]);
    expect($log->old_values)->toBe(['status' => 'active'])
        ->and($log->new_values)->toBe(['status' => 'suspended'])
        ->and($log->metadata)->toBe(['api_key_id' => 'key-123'])
        ->and($stored)->not->toContain('secret')
        ->and($stored)->not->toContain('token');
});
