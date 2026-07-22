<?php

use App\Models\AuditLog;
use App\Models\MailServer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['api.key_hash_secret' => 'mail-server-audit-test-secret']);
});

it('audits API create and update with owner and safe changed fields', function (): void {
    [$user, $token, $key] = issueMailServerApiKey(['mail_servers:write']);
    $created = $this->withToken($token)->postJson('/api/v1/mail-servers', [
        'name' => 'Audited MX', 'hostname' => 'mx.audit.test', 'provider' => 'smtp', 'protocol' => 'smtp',
        'pool_key' => 'standard', 'max_inboxes' => 5, 'metadata' => ['password' => 'never-audit', 'port' => 2525],
    ])->assertCreated();
    $server = MailServer::query()->findOrFail($created->json('data.id'));
    $createAudit = AuditLog::query()->where('action', 'mail_server.created')->sole();
    expect($createAudit->user_id)->toBe((string) $user->id)
        ->and($createAudit->metadata['source'])->toBe('api')
        ->and($createAudit->metadata['api_key_id'])->toBe((string) $key->id)
        ->and(json_encode($createAudit->toArray()))->not->toContain('never-audit');

    $this->withToken($token)->patchJson('/api/v1/mail-servers/'.$server->id, ['name' => 'Renamed MX'])
        ->assertOk();
    $updateAudit = AuditLog::query()->where('action', 'mail_server.updated')->sole();
    expect($updateAudit->old_values)->toBe(['name' => 'Audited MX'])
        ->and($updateAudit->new_values)->toBe(['name' => 'Renamed MX'])
        ->and($updateAudit->metadata['changed_fields'])->toBe(['name']);
});

it('audits Filament mutations with the authenticated user and rolls back on audit failure', function (): void {
    $admin = User::factory()->platformAdmin()->create();
    $writer = Mockery::mock(\App\Services\Audit\AuditLogWriter::class);
    $writer->shouldReceive('write')->once()->andThrow(new RuntimeException('audit failed'));
    app()->instance(\App\Services\Audit\AuditLogWriter::class, $writer);

    expect(fn () => app(\App\Actions\MailServer\CreateMailServerAction::class)->execute(
        \App\DTOs\MailServer\CreateMailServerData::fromArray(['name'=>'Rollback','hostname'=>'rollback.test','provider'=>'smtp','protocol'=>'smtp']),
        new \App\DTOs\MailServer\MailServerMutationContext((string) $admin->id, 'filament'),
    ))->toThrow(RuntimeException::class);
    expect(MailServer::query()->where('hostname', 'rollback.test')->exists())->toBeFalse();
});
