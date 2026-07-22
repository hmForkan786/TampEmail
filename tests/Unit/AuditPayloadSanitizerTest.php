<?php

use App\Services\Audit\AuditPayloadSanitizer;
use Carbon\Carbon;

it('redacts sensitive keys recursively while preserving safe audit values', function (): void {
    $payload = app(AuditPayloadSanitizer::class)->sanitize([
        'api_key_id' => 'key-123',
        'platformRole' => 'admin',
        'nested' => [
            'Password' => 'pw',
            'api-token' => 'token',
            'key_hash' => 'hash',
            'Authorization-Header' => 'Bearer secret',
            'request_body' => ['password' => 'body-secret'],
            'count' => 3,
        ],
        'changed_at' => Carbon::parse('2026-07-23 00:00:00'),
        'unsupported' => fopen('php://memory', 'r'),
    ]);

    expect($payload)->toMatchArray([
        'api_key_id' => 'key-123',
        'platformRole' => 'admin',
        'nested' => ['count' => 3],
        'changed_at' => Carbon::parse('2026-07-23 00:00:00')->toIso8601String(),
    ])
        ->and(json_encode($payload))->not->toContain('secret')
        ->and(json_encode($payload))->not->toContain('hash');
});

it('bounds deep and oversized values and returns null for null payloads', function (): void {
    $deep = ['value' => str_repeat('x', 5000)];
    for ($i = 0; $i < 10; $i++) $deep = ['nested' => $deep];

    $result = app(AuditPayloadSanitizer::class)->sanitize($deep);

    expect(app(AuditPayloadSanitizer::class)->sanitize(null))->toBeNull()
        ->and(json_encode($result))->not->toContain(str_repeat('x', 4097));
});
