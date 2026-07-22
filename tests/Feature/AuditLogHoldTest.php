<?php
use App\Actions\AuditLog\CreateAuditLogHoldAction;
use App\Actions\AuditLog\ReleaseAuditLogHoldAction;
use App\DTOs\AuditLog\CreateAuditLogHoldData;
use App\Models\AuditLog;
use App\Models\AuditLogHold;
use App\Models\User;
use App\Services\Audit\AuditLogWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
uses(RefreshDatabase::class);

function holdTarget(): AuditLog { return AuditLog::query()->create(['action'=>'mail_server.updated']); }

it('allows an active admin to create and release an indefinite hold transactionally', function (): void {
    $admin = User::factory()->platformAdmin()->create(); $log = holdTarget();
    $hold = app(CreateAuditLogHoldAction::class)->execute(new CreateAuditLogHoldData((string)$log->id,(string)$admin->id,'Incident review'));
    expect($hold->isActive())->toBeTrue()->and(AuditLog::query()->find($log->id)->holds()->count())->toBe(1)
        ->and(AuditLog::query()->where('action','audit_log.hold_created')->exists())->toBeTrue();
    $released = app(ReleaseAuditLogHoldAction::class)->execute((string)$hold->id,(string)$admin->id);
    expect($released->isActive())->toBeFalse()->and($released->released_by_user_id)->toBe((string)$admin->id)
        ->and(AuditLog::query()->where('action','audit_log.hold_released')->exists())->toBeTrue();
});

it('enforces authorization, expiry and duplicate active-hold rules', function (): void {
    $admin = User::factory()->platformAdmin()->create(); $operator = User::factory()->platformOperator()->create(); $log = holdTarget();
    expect(fn () => app(CreateAuditLogHoldAction::class)->execute(new CreateAuditLogHoldData((string)$log->id,(string)$operator->id,'x')))->toThrow(\Illuminate\Auth\Access\AuthorizationException::class);
    $hold = app(CreateAuditLogHoldAction::class)->execute(new CreateAuditLogHoldData((string)$log->id,(string)$admin->id,'x',now()->addHour()));
    expect($hold->isActive())->toBeTrue();
    expect(fn () => app(CreateAuditLogHoldAction::class)->execute(new CreateAuditLogHoldData((string)$log->id,(string)$admin->id,'duplicate')))->toThrow(InvalidArgumentException::class);
    $hold->held_until = now()->subMinute(); $hold->save(); expect($hold->fresh()->isActive())->toBeFalse();
});

it('rolls back hold creation when audit writing fails and rejects missing targets', function (): void {
    $admin = User::factory()->platformAdmin()->create();
    $writer = Mockery::mock(AuditLogWriter::class); $writer->shouldReceive('write')->once()->andThrow(new RuntimeException('audit failed')); app()->instance(AuditLogWriter::class,$writer);
    expect(fn () => app(CreateAuditLogHoldAction::class)->execute(new CreateAuditLogHoldData((string)holdTarget()->id,(string)$admin->id,'incident')))->toThrow(RuntimeException::class);
    expect(AuditLogHold::query()->count())->toBe(0);
});
