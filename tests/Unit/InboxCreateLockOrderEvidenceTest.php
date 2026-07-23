<?php

use App\Actions\Inbox\CreateInboxAction;
use App\Repositories\Eloquent\EloquentMailServerRepository;
use App\Services\Audit\AuditLogWriter;
use App\Services\MailServer\MailServerSelectionService;

/**
 * Production lock-order evidence for inbox create paths.
 *
 * This is not a concurrency proof. SQLite may run these checks. Relational
 * VERIFIED claims still require MySQL/PostgreSQL independent-process runs.
 */
it('keeps authenticated create lock order user → quota → mail server → insert → audit', function (): void {
    $action = file_get_contents((new ReflectionClass(CreateInboxAction::class))->getFileName());
    $selection = file_get_contents((new ReflectionClass(MailServerSelectionService::class))->getFileName());
    $repository = file_get_contents((new ReflectionClass(EloquentMailServerRepository::class))->getFileName());
    $audit = file_get_contents((new ReflectionClass(AuditLogWriter::class))->getFileName());

    expect($action)->not->toBeFalse()
        ->and($selection)->not->toBeFalse()
        ->and($repository)->not->toBeFalse()
        ->and($audit)->not->toBeFalse();

    $lockUser = strpos($action, 'lockUserForUpdate');
    $enforceQuota = strpos($action, 'enforceQuota');
    $selectForUser = strpos($action, 'selectForUser');
    $create = strpos($action, 'inboxRepository->create');
    $auditWrite = strpos($action, 'auditLogWriter->write');
    $repoLock = strpos($repository, 'lockForUpdate');
    $selectionDelegates = strpos($selection, 'selectAvailableForPoolsForUpdate');

    expect($lockUser)->toBeInt()
        ->and($enforceQuota)->toBeInt()
        ->and($selectForUser)->toBeInt()
        ->and($create)->toBeInt()
        ->and($auditWrite)->toBeInt()
        ->and($repoLock)->toBeInt()
        ->and($selectionDelegates)->toBeInt()
        ->and($lockUser)->toBeLessThan($enforceQuota)
        ->and($enforceQuota)->toBeLessThan($selectForUser)
        ->and($selectForUser)->toBeLessThan($create)
        ->and($create)->toBeLessThan($auditWrite);
})->group('relational-inbox');

it('keeps anonymous create lock order mail server → insert → audit', function (): void {
    $action = file_get_contents((new ReflectionClass(CreateInboxAction::class))->getFileName());
    $repository = file_get_contents((new ReflectionClass(EloquentMailServerRepository::class))->getFileName());

    expect($action)->not->toBeFalse()->and($repository)->not->toBeFalse();

    $anonymousBranch = strpos($action, "config('inbox.public_mail_server_pool')");
    $selectPublic = strpos($action, 'selectAvailableForPoolsForUpdate');
    $create = strpos($action, 'inboxRepository->create');
    $auditWrite = strpos($action, 'auditLogWriter->write');
    $repoLock = strpos($repository, 'lockForUpdate');

    expect($anonymousBranch)->toBeInt()
        ->and($selectPublic)->toBeInt()
        ->and($create)->toBeInt()
        ->and($auditWrite)->toBeInt()
        ->and($repoLock)->toBeInt()
        ->and($anonymousBranch)->toBeLessThan($selectPublic)
        ->and($selectPublic)->toBeLessThan($create)
        ->and($create)->toBeLessThan($auditWrite);
})->group('relational-inbox');
