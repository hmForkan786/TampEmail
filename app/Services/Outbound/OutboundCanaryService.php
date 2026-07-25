<?php

declare(strict_types=1);

namespace App\Services\Outbound;

use App\Enums\OutboundCanarySubjectType;
use App\Exceptions\OutboundSendException;
use App\Models\ApiKey;
use App\Models\Domain;
use App\Models\Inbox;
use App\Models\OutboundLaunchCanary;
use App\Models\User;
use App\Services\Audit\AuditLogWriter;
use Illuminate\Database\Eloquent\Collection;

/**
 * Admin-managed outbound launch canary membership (user, inbox, domain, or
 * API key — the smallest scope that fits each identity). Canary membership
 * only feeds the rollout eligibility check in
 * {@see OutboundLaunchControlService}; it never bypasses domain
 * verification, suppression, quotas, or worker readiness, which are all
 * evaluated independently elsewhere.
 */
final class OutboundCanaryService
{
    public function __construct(
        private readonly AuditLogWriter $audit,
    ) {}

    public function add(OutboundCanarySubjectType $type, string $subjectId, User $actor, ?string $label = null): OutboundLaunchCanary
    {
        if (! $this->subjectExists($type, $subjectId)) {
            throw new OutboundSendException('canary_subject_not_found', 'The canary subject was not found.', 404);
        }

        $existing = OutboundLaunchCanary::query()
            ->where('subject_type', $type->value)
            ->where('subject_id', $subjectId)
            ->where('active', true)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $canary = OutboundLaunchCanary::query()->create([
            'subject_type' => $type,
            'subject_id' => $subjectId,
            'label' => $label !== null ? mb_substr(trim($label), 0, 255) : null,
            'active' => true,
            'added_by' => $actor->getKey(),
            'added_at' => now(),
        ]);

        $this->audit->write(
            'outbound.canary_added',
            (string) $actor->getKey(),
            $canary,
            null,
            ['active' => true],
            [
                'canary_id' => (string) $canary->getKey(),
                'subject_type' => $type->value,
                'subject_id' => $subjectId,
            ],
        );

        return $canary;
    }

    public function remove(OutboundLaunchCanary $canary, User $actor): OutboundLaunchCanary
    {
        if (! $canary->active) {
            return $canary;
        }

        $canary->forceFill([
            'active' => false,
            'removed_by' => $actor->getKey(),
            'removed_at' => now(),
        ])->save();

        $this->audit->write(
            'outbound.canary_removed',
            (string) $actor->getKey(),
            $canary,
            ['active' => true],
            ['active' => false],
            [
                'canary_id' => (string) $canary->getKey(),
                'subject_type' => $canary->subject_type->value,
                'subject_id' => $canary->subject_id,
            ],
        );

        return $canary->fresh();
    }

    /**
     * @return Collection<int, OutboundLaunchCanary>
     */
    public function active(): Collection
    {
        return OutboundLaunchCanary::query()->active()->orderByDesc('added_at')->get();
    }

    public function hasActiveCanaries(): bool
    {
        return OutboundLaunchCanary::query()->active()->exists();
    }

    /**
     * Determine whether the acting user/inbox/domain/api-key is a
     * currently active canary subject. Domain is resolved from the inbox
     * relation when loaded.
     */
    public function matches(User $user, Inbox $inbox, ?string $apiKeyId = null): bool
    {
        $domainId = $inbox->relationLoaded('domain') ? $inbox->domain?->getKey() : $inbox->domain_id;

        $query = OutboundLaunchCanary::query()->active()->where(function ($q) use ($user, $inbox, $domainId, $apiKeyId): void {
            $q->where(function ($sub) use ($user): void {
                $sub->where('subject_type', OutboundCanarySubjectType::User->value)
                    ->where('subject_id', (string) $user->getKey());
            })->orWhere(function ($sub) use ($inbox): void {
                $sub->where('subject_type', OutboundCanarySubjectType::Inbox->value)
                    ->where('subject_id', (string) $inbox->getKey());
            });

            if ($domainId !== null) {
                $q->orWhere(function ($sub) use ($domainId): void {
                    $sub->where('subject_type', OutboundCanarySubjectType::Domain->value)
                        ->where('subject_id', (string) $domainId);
                });
            }

            if ($apiKeyId !== null) {
                $q->orWhere(function ($sub) use ($apiKeyId): void {
                    $sub->where('subject_type', OutboundCanarySubjectType::ApiKey->value)
                        ->where('subject_id', $apiKeyId);
                });
            }
        });

        return $query->exists();
    }

    private function subjectExists(OutboundCanarySubjectType $type, string $subjectId): bool
    {
        return match ($type) {
            OutboundCanarySubjectType::User => User::query()->whereKey($subjectId)->exists(),
            OutboundCanarySubjectType::Inbox => Inbox::query()->whereKey($subjectId)->exists(),
            OutboundCanarySubjectType::Domain => Domain::query()->whereKey($subjectId)->exists(),
            OutboundCanarySubjectType::ApiKey => ApiKey::query()->whereKey($subjectId)->exists(),
        };
    }
}
