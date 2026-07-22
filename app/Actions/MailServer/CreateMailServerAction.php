<?php
declare(strict_types=1);
namespace App\Actions\MailServer;
use App\DTOs\MailServer\CreateMailServerData;
use App\DTOs\MailServer\MailServerMutationContext;
use App\Models\MailServer;
use App\Models\User;
use App\Repositories\Contracts\MailServerRepositoryInterface;
use App\Services\Audit\AuditLogWriter;
use App\Support\MailServerProvisioningInvariant;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class CreateMailServerAction
{
    public function __construct(private readonly MailServerRepositoryInterface $mailServerRepository, private readonly AuditLogWriter $auditLogWriter) {}
    public function execute(CreateMailServerData $data, MailServerMutationContext $context): MailServer
    {
        return DB::transaction(function () use ($data, $context): MailServer {
            $actor = User::query()->whereKey($context->actorUserId)->lockForUpdate()->first();
            if (! $actor instanceof User || ! $actor->isPlatformOperator() || ! Gate::forUser($actor)->allows('create', MailServer::class)) throw new AuthorizationException('Mail-server creation is not allowed.');
            $data = $data->withProvisioningFields(MailServerProvisioningInvariant::poolKey($data->poolKey), MailServerProvisioningInvariant::maxInboxes($data->maxInboxes));
            $server = $this->mailServerRepository->create($data);
            $at = now();
            $this->auditLogWriter->write('mail_server.created', (string) $actor->getKey(), $server, null, self::safeValues($server), self::metadata($context, $at), $at);
            return $server;
        });
    }
    /** @return array<string,mixed> */
    public static function safeValues(MailServer $server): array
    {
        return ['name'=>$server->name,'hostname'=>$server->hostname,'port'=>$server->metadata['port']??null,'provider'=>$server->provider,'protocol'=>$server->protocol,'pool_key'=>$server->pool_key,'max_inboxes'=>$server->max_inboxes,'is_active'=>$server->is_active,'priority'=>$server->priority,'max_connections'=>$server->max_connections,'timeout_seconds'=>$server->timeout_seconds,'last_health_check_at'=>$server->last_health_check_at?->toIso8601String()];
    }
    /** @return array<string,mixed> */
    public static function metadata(MailServerMutationContext $context, \Carbon\CarbonInterface $at): array
    { return ['source'=>$context->source,'api_key_id'=>$context->apiKeyId,'changed_at'=>$at->toIso8601String()]; }
}
