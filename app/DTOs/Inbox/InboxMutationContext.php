<?php

declare(strict_types=1);

namespace App\DTOs\Inbox;

use InvalidArgumentException;

/**
 * Explicit mutation provenance for inbox lifecycle writes.
 *
 * Construct only via factories so callers cannot invent arbitrary sources.
 */
final readonly class InboxMutationContext
{
    public const SOURCE_API = 'api';

    public const SOURCE_ANONYMOUS = 'anonymous';

    public const SOURCE_SCHEDULER = 'scheduler';

    /** @var list<string> */
    private const SOURCES = [self::SOURCE_API, self::SOURCE_ANONYMOUS, self::SOURCE_SCHEDULER];

    private function __construct(
        public ?string $actorUserId,
        public string $source,
        public ?string $apiKeyId = null,
    ) {
        if (! in_array($this->source, self::SOURCES, true)) {
            throw new InvalidArgumentException('Invalid inbox mutation source.');
        }

        if ($this->source === self::SOURCE_API) {
            if ($this->actorUserId === null || $this->actorUserId === '') {
                throw new InvalidArgumentException('A mutation actor is required.');
            }
            if ($this->apiKeyId === null || $this->apiKeyId === '') {
                throw new InvalidArgumentException('An API key ID is required for API mutations.');
            }
        }

        if ($this->source === self::SOURCE_ANONYMOUS && ($this->actorUserId !== null || $this->apiKeyId !== null)) {
            throw new InvalidArgumentException('Anonymous context cannot include an actor or API key.');
        }

        if ($this->source === self::SOURCE_SCHEDULER && ($this->actorUserId !== null || $this->apiKeyId !== null)) {
            throw new InvalidArgumentException('Scheduler context cannot include an actor or API key.');
        }
    }

    public static function forApi(string $actorUserId, string $apiKeyId): self
    {
        return new self($actorUserId, self::SOURCE_API, $apiKeyId);
    }

    public static function forAnonymous(): self
    {
        return new self(null, self::SOURCE_ANONYMOUS, null);
    }

    public static function forScheduler(): self
    {
        return new self(null, self::SOURCE_SCHEDULER, null);
    }

    public function isApi(): bool
    {
        return $this->source === self::SOURCE_API;
    }

    public function isAnonymous(): bool
    {
        return $this->source === self::SOURCE_ANONYMOUS;
    }

    public function isScheduler(): bool
    {
        return $this->source === self::SOURCE_SCHEDULER;
    }

    /**
     * Reject combinations that are invalid for authenticated create/renew/delete.
     */
    public function assertApiMutation(string $ownerUserId): void
    {
        if (! $this->isApi()) {
            throw new InvalidArgumentException('Only an API mutation context may mutate a user-owned inbox.');
        }

        if ($this->actorUserId !== $ownerUserId) {
            throw new InvalidArgumentException('Mutation actor must match the inbox owner.');
        }
    }

    /**
     * Reject combinations that are invalid for anonymous create.
     */
    public function assertAnonymousCreate(): void
    {
        if (! $this->isAnonymous()) {
            throw new InvalidArgumentException('Anonymous inbox creation requires an anonymous mutation context.');
        }
    }

    /**
     * Reject combinations that are invalid for scheduled expiration.
     */
    public function assertSchedulerExpiration(): void
    {
        if (! $this->isScheduler()) {
            throw new InvalidArgumentException('Inbox expiration requires a scheduler mutation context.');
        }
    }
}
