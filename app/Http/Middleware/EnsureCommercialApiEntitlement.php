<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\ApiKey\AuthenticatedApiKeyContext;
use App\Services\Audit\AuditLogWriter;
use App\Services\Commercial\CommercialApiErrorMapper;
use App\Services\Entitlement\EntitlementService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureCommercialApiEntitlement
{
    public function __construct(
        private readonly EntitlementService $entitlements,
        private readonly AuditLogWriter $audit,
    ) {}

    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $context = $request->attributes->get('apiKeyContext');
        $user = $context instanceof AuthenticatedApiKeyContext ? $context->owner : $request->attributes->get('apiKeyOwner');

        if (! $user instanceof User || ! $this->entitlements->allows($user, $feature)) {
            $this->audit->write($this->auditAction($feature), $user instanceof User ? (string) $user->getKey() : null, null, null, null, [
                'feature' => $feature,
                'method' => $request->method(),
                'path' => $request->path(),
            ]);

            return CommercialApiErrorMapper::featureDenied($feature);
        }

        return $next($request);
    }

    private function auditAction(string $feature): string
    {
        return match ($feature) {
            'api.read' => 'commercial.api_read_denied',
            'api.write' => 'commercial.api_write_denied',
            'webhook.access' => 'commercial.webhook_access_denied',
            default => 'commercial.api_denied',
        };
    }
}
