<?php
declare(strict_types=1);
namespace App\DTOs\MailServer;
use InvalidArgumentException;
final readonly class MailServerMutationContext
{
    public function __construct(public string $actorUserId, public string $source, public ?string $apiKeyId = null)
    {
        if ($actorUserId === '') throw new InvalidArgumentException('A mutation actor is required.');
        if (! in_array($source, ['api', 'filament'], true)) throw new InvalidArgumentException('Invalid mutation source.');
        if ($source === 'filament' && $apiKeyId !== null) throw new InvalidArgumentException('Filament cannot include an API key.');
    }
}
