<?php
declare(strict_types=1);
namespace App\Actions\MailServer;
use App\DTOs\MailServer\MailServerMutationContext;
use App\DTOs\MailServer\UpdateMailServerData;
use App\Models\MailServer;
use App\Models\User;
use App\Repositories\Contracts\MailServerRepositoryInterface;
use App\Services\Audit\AuditLogWriter;
use App\Support\MailServerProvisioningInvariant;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class UpdateMailServerAction
{
    public function __construct(private readonly MailServerRepositoryInterface $mailServerRepository, private readonly AuditLogWriter $auditLogWriter) {}
    public function execute(MailServer $server, UpdateMailServerData $data, MailServerMutationContext $context): MailServer
    {
        return DB::transaction(function () use ($server, $data, $context): MailServer {
            $actor = User::query()->whereKey($context->actorUserId)->lockForUpdate()->first();
            $target = MailServer::query()->whereKey($server->getKey())->lockForUpdate()->first();
            if (! $actor instanceof User || ! $target instanceof MailServer || ! $actor->isPlatformOperator() || ! Gate::forUser($actor)->allows('update', $target)) throw new AuthorizationException('Mail-server update is not allowed.');
            $old = CreateMailServerAction::safeValues($target);
            $data = $data->withProvisioningFields($data->poolKey === null ? null : MailServerProvisioningInvariant::poolKey($data->poolKey), $data->maxInboxes === null ? null : MailServerProvisioningInvariant::maxInboxes($data->maxInboxes));
            $updated = $this->mailServerRepository->update($target, $data);
            $new = CreateMailServerAction::safeValues($updated); $oldValues = $newValues = [];
            foreach ($old as $field => $value) if ($value !== $new[$field]) { $oldValues[$field] = $value; $newValues[$field] = $new[$field]; }
            if ($oldValues !== []) { $at = now(); $this->auditLogWriter->write('mail_server.updated', (string)$actor->getKey(), $updated, $oldValues, $newValues, array_merge(CreateMailServerAction::metadata($context, $at), ['changed_fields'=>array_keys($oldValues)]), $at); }
            return $updated;
        });
    }
}
