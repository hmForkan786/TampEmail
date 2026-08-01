<?php

declare(strict_types=1);

namespace App\Services\Identity;

use App\Enums\RegistrationMode;
use App\Models\RegistrationInvite;
use App\Models\User;
use App\Services\Audit\AuditLogWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Invite-only registration token lifecycle. Raw tokens are shown once; hashes are stored.
 */
final class InviteService
{
    public function __construct(
        private readonly AuditLogWriter $audit,
    ) {}

    /**
     * @return array{invite: RegistrationInvite, plain_token: string}
     */
    public function create(?string $email, int $maxUses, ?\DateTimeInterface $expiresAt, User $actor): array
    {
        $plain = Str::random(64);
        $hash = hash('sha256', $plain);

        $invite = RegistrationInvite::query()->create([
            'email' => $email !== null && $email !== '' ? strtolower(trim($email)) : null,
            'token_hash' => $hash,
            'max_uses' => max(1, $maxUses),
            'uses' => 0,
            'expires_at' => $expiresAt,
            'created_by' => $actor->getKey(),
        ]);

        $this->audit->write('identity.invite_created', (string) $actor->getKey(), $invite, metadata: [
            'invite_id' => (string) $invite->getKey(),
            'max_uses' => $invite->max_uses,
            'email_locked' => $invite->email !== null,
        ]);

        return ['invite' => $invite, 'plain_token' => $plain];
    }

    public function revoke(RegistrationInvite $invite, User $actor): RegistrationInvite
    {
        if ($invite->revoked_at === null) {
            $invite->forceFill(['revoked_at' => now()])->save();
            $this->audit->write('identity.invite_revoked', (string) $actor->getKey(), $invite);
        }

        return $invite->fresh() ?? $invite;
    }

    public function consume(string $plainToken, string $normalizedEmail): RegistrationInvite
    {
        $plainToken = trim($plainToken);
        if ($plainToken === '' || strlen($plainToken) < 32) {
            throw ValidationException::withMessages([
                'invite_token' => __('A valid invitation is required.'),
            ]);
        }

        $hash = hash('sha256', $plainToken);

        return DB::transaction(function () use ($hash, $normalizedEmail): RegistrationInvite {
            $invite = RegistrationInvite::query()
                ->where('token_hash', $hash)
                ->lockForUpdate()
                ->first();

            if (! $invite instanceof RegistrationInvite || ! $invite->isUsable()) {
                throw ValidationException::withMessages([
                    'invite_token' => __('A valid invitation is required.'),
                ]);
            }

            if ($invite->email !== null && $invite->email !== $normalizedEmail) {
                throw ValidationException::withMessages([
                    'invite_token' => __('A valid invitation is required.'),
                ]);
            }

            $invite->forceFill(['uses' => $invite->uses + 1])->save();

            return $invite->fresh() ?? $invite;
        });
    }

    public function expireDue(int $limit = 200): int
    {
        $count = 0;
        RegistrationInvite::query()
            ->whereNull('revoked_at')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->orderBy('expires_at')
            ->limit($limit)
            ->get()
            ->each(function (RegistrationInvite $invite) use (&$count): void {
                $invite->forceFill(['revoked_at' => now()])->save();
                $count++;
            });

        return $count;
    }

    public function modeRequiresInvite(): bool
    {
        return RegistrationMode::fromConfig((string) config('identity.registration.mode')) === RegistrationMode::InviteOnly;
    }
}
