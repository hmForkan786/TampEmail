<?php

declare(strict_types=1);

namespace App\Services\Outbound;

use App\Enums\OutboundMessageState;
use App\Enums\OutboundOperation;
use App\Enums\UserStatus;
use App\Exceptions\OutboundSendException;
use App\Models\Inbox;
use App\Models\OutboundMessage;
use App\Models\OutboundSenderProfile;
use App\Models\User;
use App\Services\Audit\AuditLogWriter;
use App\Services\Entitlement\EntitlementService;
use App\Services\Inbound\InboundHtmlSanitizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Secure per-inbox sender identity profiles for outbound drafts and sends. */
final class OutboundSenderProfileService
{
    public const TEXT_SIG_START = '[[outbound-sig-start]]';

    public const TEXT_SIG_END = '[[outbound-sig-end]]';

    public const HTML_SIG_START = '<!--outbound-sig-start-->';

    public const HTML_SIG_END = '<!--outbound-sig-end-->';

    public function __construct(
        private readonly OutboundHeaderGuard $headers,
        private readonly InboundHtmlSanitizer $htmlSanitizer,
        private readonly AuditLogWriter $audit,
        private readonly EntitlementService $entitlements,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('outbound.sender_profiles.enabled', true);
    }

    /**
     * @return Collection<int, OutboundSenderProfile>
     */
    public function list(User $user, ?string $inboxId = null): Collection
    {
        $this->assertFeatureEnabled();

        return OutboundSenderProfile::query()
            ->with('inbox')
            ->where('user_id', $user->getKey())
            ->when($inboxId !== null, fn (Builder $q): Builder => $q->where('inbox_id', $inboxId))
            ->orderBy('inbox_id')
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();
    }

    public function findOwned(User $user, string $profileId): OutboundSenderProfile
    {
        $this->assertFeatureEnabled();
        $profile = OutboundSenderProfile::query()
            ->whereKey($profileId)
            ->where('user_id', $user->getKey())
            ->first();

        if ($profile === null) {
            throw new OutboundSendException('profile_not_found', 'Sender profile not found.', 404);
        }

        return $profile;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function create(User $user, array $input): OutboundSenderProfile
    {
        $this->assertFeatureEnabled();
        $this->assertCommercialAccess($user);
        $this->assertUserActive($user);
        $inbox = $this->assertOwnedInbox($user, (string) $input['inbox_id']);
        $validated = $this->validateFields($user, $input, $inbox);

        return DB::transaction(function () use ($user, $inbox, $validated, $input): OutboundSenderProfile {
            $this->assertProfileLimit($user, $inbox);
            $this->assertUniqueName($user, $inbox, $validated['name']);

            $makeDefault = (bool) ($input['is_default'] ?? false);
            if ($makeDefault) {
                $this->clearDefault($user, $inbox);
            } elseif (! OutboundSenderProfile::query()->where('user_id', $user->getKey())->where('inbox_id', $inbox->getKey())->exists()) {
                $makeDefault = true;
            }

            $profile = OutboundSenderProfile::query()->create([
                'user_id' => $user->getKey(),
                'inbox_id' => $inbox->getKey(),
                ...$validated,
                'is_default' => $makeDefault,
                'version' => 1,
            ]);

            $this->incrementMetric('create');
            $this->auditProfile($user, 'outbound.sender_profile_created', $profile, [
                'inbox_id' => (string) $inbox->getKey(),
                'profile_id' => (string) $profile->getKey(),
            ]);

            return $profile;
        });
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function update(User $user, string $profileId, array $input, ?int $version = null): OutboundSenderProfile
    {
        $this->assertFeatureEnabled();
        $this->assertCommercialAccess($user);
        $this->assertUserActive($user);

        return DB::transaction(function () use ($user, $profileId, $input, $version): OutboundSenderProfile {
            $profile = OutboundSenderProfile::query()
                ->whereKey($profileId)
                ->where('user_id', $user->getKey())
                ->lockForUpdate()
                ->first();

            if ($profile === null) {
                throw new OutboundSendException('profile_not_found', 'Sender profile not found.', 404);
            }

            if ($version !== null && $profile->version !== $version) {
                throw new OutboundSendException('profile_conflict', 'This sender profile has changed. Refresh and try again.', 409);
            }

            $inbox = $this->assertOwnedInbox($user, (string) $profile->inbox_id);
            $validated = $this->validateFields($user, $input, $inbox, $profile);

            if (array_key_exists('name', $input) && $validated['name'] !== $profile->name) {
                $this->assertUniqueName($user, $inbox, $validated['name'], (string) $profile->getKey());
            }

            if (array_key_exists('is_default', $input) && (bool) $input['is_default']) {
                $this->clearDefault($user, $inbox, (string) $profile->getKey());
            }

            $profile->fill($validated);
            if (array_key_exists('is_default', $input)) {
                $profile->is_default = (bool) $input['is_default'];
            }
            $profile->version++;
            $profile->save();

            $this->incrementMetric('update');
            $this->auditProfile($user, 'outbound.sender_profile_updated', $profile, [
                'inbox_id' => (string) $inbox->getKey(),
                'profile_id' => (string) $profile->getKey(),
                'version' => $profile->version,
            ]);

            return $profile;
        });
    }

    public function delete(User $user, string $profileId, ?int $version = null): void
    {
        $this->assertFeatureEnabled();
        $this->assertCommercialAccess($user);

        DB::transaction(function () use ($user, $profileId, $version): void {
            $profile = OutboundSenderProfile::query()
                ->whereKey($profileId)
                ->where('user_id', $user->getKey())
                ->lockForUpdate()
                ->first();

            if ($profile === null) {
                throw new OutboundSendException('profile_not_found', 'Sender profile not found.', 404);
            }

            if ($version !== null && $profile->version !== $version) {
                throw new OutboundSendException('profile_conflict', 'This sender profile has changed. Refresh and try again.', 409);
            }

            $profile->forceFill([
                'display_name' => null,
                'reply_to_address' => null,
                'reply_to_name' => null,
                'signature_text' => null,
                'signature_html' => null,
            ])->save();

            $profile->delete();

            OutboundMessage::query()
                ->where('user_id', $user->getKey())
                ->where('sender_profile_id', $profile->getKey())
                ->where('state', OutboundMessageState::Draft->value)
                ->update(['sender_profile_id' => null]);

            $this->incrementMetric('delete');
            $this->auditProfile($user, 'outbound.sender_profile_deleted', $profile, [
                'inbox_id' => (string) $profile->inbox_id,
                'profile_id' => (string) $profile->getKey(),
            ]);
        });
    }

    public function makeDefault(User $user, string $profileId, ?int $version = null): OutboundSenderProfile
    {
        $this->assertFeatureEnabled();
        $this->assertCommercialAccess($user);

        return DB::transaction(function () use ($user, $profileId, $version): OutboundSenderProfile {
            $profile = OutboundSenderProfile::query()
                ->whereKey($profileId)
                ->where('user_id', $user->getKey())
                ->lockForUpdate()
                ->first();

            if ($profile === null) {
                throw new OutboundSendException('profile_not_found', 'Sender profile not found.', 404);
            }

            if ($version !== null && $profile->version !== $version) {
                throw new OutboundSendException('profile_conflict', 'This sender profile has changed. Refresh and try again.', 409);
            }

            if (! $profile->is_active) {
                throw new OutboundSendException('profile_inactive', 'Inactive sender profiles cannot be default.', 422);
            }

            $inbox = $this->assertOwnedInbox($user, (string) $profile->inbox_id);
            $this->clearDefault($user, $inbox, (string) $profile->getKey());
            $profile->forceFill(['is_default' => true, 'version' => $profile->version + 1])->save();

            $this->auditProfile($user, 'outbound.sender_profile_defaulted', $profile, [
                'inbox_id' => (string) $inbox->getKey(),
                'profile_id' => (string) $profile->getKey(),
            ]);

            return $profile;
        });
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function resolveDraftProfileFields(User $user, Inbox $inbox, OutboundOperation $operation, array $input, ?OutboundMessage $current = null): array
    {
        if (! $this->enabled()) {
            return $this->clearProfileFields($current);
        }

        $inboxChanged = $current !== null
            && array_key_exists('inbox_id', $input)
            && (string) $input['inbox_id'] !== (string) $current->inbox_id;

        $explicitProfileId = array_key_exists('sender_profile_id', $input)
            ? ($input['sender_profile_id'] !== null ? (string) $input['sender_profile_id'] : null)
            : ($inboxChanged ? null : ($current?->sender_profile_id !== null ? (string) $current->sender_profile_id : null));

        $explicitDisplayName = array_key_exists('from_display_name', $input)
            ? $input['from_display_name']
            : ($inboxChanged ? null : $current?->from_display_name);

        $profile = null;
        if ($explicitProfileId !== null && $explicitProfileId !== '') {
            $this->assertCommercialAccess($user);
            try {
                $profile = $this->findOwned($user, $explicitProfileId);
                $this->assertProfileUsable($profile, $inbox);
            } catch (OutboundSendException $exception) {
                $this->recordRejected($user, $exception->errorCode, $profile ?? null);
                throw $exception;
            }
        } elseif ($explicitProfileId === null && ! array_key_exists('sender_profile_id', $input) && ! $inboxChanged) {
            $profile = $this->resolveDefaultForInbox($user, $inbox);
        } elseif ($explicitProfileId === null && ! array_key_exists('sender_profile_id', $input) && $inboxChanged) {
            $profile = $this->resolveDefaultForInbox($user, $inbox);
        }

        if ($profile === null && $explicitProfileId !== null && $explicitProfileId !== '') {
            throw new OutboundSendException('profile_not_found', 'Sender profile not found.', 404);
        }

        if ($profile !== null) {
            $identity = $this->snapshotFieldsForMessage($profile);
            if ($explicitDisplayName !== null) {
                $identity['from_display_name'] = $this->validateDisplayName($explicitDisplayName);
            }

            return [
                'sender_profile_id' => $profile->getKey(),
                'from_display_name' => $identity['from_display_name'],
                'reply_to_address' => $identity['reply_to_address'],
                'reply_to_name' => $identity['reply_to_name'],
                '_profile_for_signature' => $profile,
            ];
        }

        $displayName = $explicitDisplayName !== null ? $this->validateDisplayName($explicitDisplayName) : null;
        $replyToAddress = array_key_exists('reply_to_address', $input) ? $input['reply_to_address'] : ($inboxChanged ? null : $current?->reply_to_address);
        $replyToName = array_key_exists('reply_to_name', $input) ? $input['reply_to_name'] : ($inboxChanged ? null : $current?->reply_to_name);

        if ($replyToAddress !== null && trim((string) $replyToAddress) !== '') {
            $this->assertReplyToOwned($user, (string) $replyToAddress);
            $replyToAddress = $this->headers->assertSafeEmail((string) $replyToAddress, 'reply_to');
        } else {
            $replyToAddress = null;
        }

        if ($replyToName !== null && trim((string) $replyToName) !== '') {
            $replyToName = $this->sanitizeOptionalHeader((string) $replyToName, 'reply_to_name', 255);
        } else {
            $replyToName = null;
        }

        return [
            'sender_profile_id' => null,
            'from_display_name' => $displayName,
            'reply_to_address' => $replyToAddress,
            'reply_to_name' => $replyToName,
            '_profile_for_signature' => null,
        ];
    }

    /**
     * @return array{text_body: ?string, html_body: ?string}
     */
    public function applySignatureToBodies(?string $text, ?string $html, ?OutboundSenderProfile $profile, OutboundOperation $operation): array
    {
        if ($profile === null || ! $this->shouldIncludeSignature($profile, $operation)) {
            return ['text_body' => $text, 'html_body' => $html];
        }

        $textBlock = $profile->signature_text !== null && trim($profile->signature_text) !== ''
            ? self::TEXT_SIG_START."\n".trim($profile->signature_text)."\n".self::TEXT_SIG_END
            : null;
        $htmlBlock = $profile->signature_html !== null && trim($profile->signature_html) !== ''
            ? self::HTML_SIG_START.trim($profile->signature_html).self::HTML_SIG_END
            : null;

        return [
            'text_body' => $textBlock !== null ? $this->insertSignatureBlock($text, $textBlock, isHtml: false) : $text,
            'html_body' => $htmlBlock !== null ? $this->insertSignatureBlock($html, $htmlBlock, isHtml: true) : $html,
        ];
    }

    /**
     * @return array{from_display_name: ?string, reply_to_address: ?string, reply_to_name: ?string}
     */
    public function snapshotFieldsForMessage(?OutboundSenderProfile $profile): array
    {
        if ($profile === null) {
            return [
                'from_display_name' => null,
                'reply_to_address' => null,
                'reply_to_name' => null,
            ];
        }

        return [
            'from_display_name' => $profile->display_name !== null && trim($profile->display_name) !== ''
                ? $this->validateDisplayName($profile->display_name)
                : null,
            'reply_to_address' => $profile->reply_to_address,
            'reply_to_name' => $profile->reply_to_name,
        ];
    }

    public function assertProfileUsable(OutboundSenderProfile $profile, Inbox $inbox): void
    {
        if (! $profile->isUsable()) {
            throw new OutboundSendException('profile_inactive', 'The sender profile is inactive.', 422);
        }

        if ((string) $profile->inbox_id !== (string) $inbox->getKey()) {
            throw new OutboundSendException('profile_inbox_mismatch', 'The sender profile does not belong to this inbox.', 422);
        }
    }

    /**
     * @return array{
     *     from_display_name: ?string,
     *     reply_to_address: ?string,
     *     reply_to_name: ?string,
     *     text_body: ?string,
     *     html_body: ?string,
     * }
     */
    public function resolveForSend(OutboundMessage $message, User $user, Inbox $inbox): array
    {
        $text = $this->stripSignatureMarkers($message->text_body, false);
        $html = $this->stripSignatureMarkers($message->html_body, true);

        if ($message->state === OutboundMessageState::Draft) {
            if ($message->sender_profile_id !== null) {
                $this->assertCommercialAccess($user);
                try {
                    $profile = $this->findOwned($user, (string) $message->sender_profile_id);
                    $this->assertProfileUsable($profile, $inbox);
                    $snapshot = $this->snapshotFieldsForMessage($profile);
                    $fromDisplayName = $message->from_display_name ?? $snapshot['from_display_name'];
                    $replyToAddress = $message->reply_to_address ?? $snapshot['reply_to_address'];
                    $replyToName = $message->reply_to_name ?? $snapshot['reply_to_name'];
                } catch (OutboundSendException $exception) {
                    if (in_array($exception->errorCode, ['profile_not_found', 'profile_inactive', 'profile_inbox_mismatch'], true)
                        && ($message->from_display_name !== null || $message->reply_to_address !== null)) {
                        $fromDisplayName = $message->from_display_name;
                        $replyToAddress = $message->reply_to_address;
                        $replyToName = $message->reply_to_name;
                    } else {
                        $this->recordRejected($user, $exception->errorCode);
                        throw $exception;
                    }
                }
            } else {
                $fromDisplayName = $message->from_display_name;
                $replyToAddress = $message->reply_to_address;
                $replyToName = $message->reply_to_name;
            }
        } else {
            $fromDisplayName = $message->from_display_name;
            $replyToAddress = $message->reply_to_address;
            $replyToName = $message->reply_to_name;
        }

        if ($replyToAddress !== null && trim($replyToAddress) !== '') {
            $this->assertReplyToOwned($user, $replyToAddress);
            $replyToAddress = $this->headers->assertSafeEmail($replyToAddress, 'reply_to');
        } else {
            $replyToAddress = null;
        }

        if ($replyToName !== null && trim($replyToName) !== '') {
            $replyToName = $this->sanitizeOptionalHeader($replyToName, 'reply_to_name', 255);
        } else {
            $replyToName = null;
        }

        if ($fromDisplayName !== null && trim($fromDisplayName) !== '') {
            $fromDisplayName = $this->validateDisplayName($fromDisplayName);
        } else {
            $fromDisplayName = null;
        }

        return [
            'from_display_name' => $fromDisplayName,
            'reply_to_address' => $replyToAddress,
            'reply_to_name' => $replyToName,
            'text_body' => $text,
            'html_body' => $html,
        ];
    }

    public function stripSignatureMarkers(?string $body, bool $isHtml): ?string
    {
        if ($body === null || $body === '') {
            return $body;
        }

        if ($isHtml) {
            return preg_replace(
                '/'.preg_quote(self::HTML_SIG_START, '/').'|'.preg_quote(self::HTML_SIG_END, '/').'/',
                '',
                $body,
            ) ?? $body;
        }

        return preg_replace(
            '/'.preg_quote(self::TEXT_SIG_START, '/').'|'.preg_quote(self::TEXT_SIG_END, '/').'/',
            '',
            $body,
        ) ?? $body;
    }

    public function assertReplyToOwned(User $user, string $address): void
    {
        $normalized = strtolower(trim($address));
        if ($normalized === '') {
            return;
        }

        $owned = Inbox::query()
            ->where('user_id', $user->getKey())
            ->where('full_address', $normalized)
            ->where('is_active', true)
            ->where(function (Builder $q): void {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->exists();

        if (! $owned) {
            throw new OutboundSendException('reply_to_forbidden', 'Reply-To must be an address of one of your active inboxes.', 422);
        }
    }

    /**
     * @return array<string, int>
     */
    public function metrics(): array
    {
        return [
            'active_profiles' => OutboundSenderProfile::query()->where('is_active', true)->count(),
            'default_profiles' => OutboundSenderProfile::query()->where('is_default', true)->where('is_active', true)->count(),
            'created' => (int) Cache::get('outbound.metrics.sender_profile_created', 0),
            'updated' => (int) Cache::get('outbound.metrics.sender_profile_updated', 0),
            'deleted' => (int) Cache::get('outbound.metrics.sender_profile_deleted', 0),
            'applied' => (int) Cache::get('outbound.metrics.sender_profile_applied', 0),
            'rejected' => (int) Cache::get('outbound.metrics.sender_profile_rejected', 0),
        ];
    }

    public function redactProfilesForDeletedUser(string $userId): int
    {
        $count = 0;
        OutboundSenderProfile::query()
            ->withTrashed()
            ->where('user_id', $userId)
            ->chunkById(100, function ($profiles) use (&$count): void {
                foreach ($profiles as $profile) {
                    $profile->forceFill([
                        'display_name' => null,
                        'reply_to_address' => null,
                        'reply_to_name' => null,
                        'signature_text' => null,
                        'signature_html' => null,
                    ])->save();
                    $count++;
                }
            });

        return $count;
    }

    public function recordApplied(User $user, OutboundSenderProfile $profile, Inbox $inbox): void
    {
        $this->incrementMetric('applied');
        $this->audit->write(
            'outbound.sender_profile_applied',
            (string) $user->getKey(),
            $profile,
            null,
            null,
            [
                'inbox_id' => (string) $inbox->getKey(),
                'profile_id' => (string) $profile->getKey(),
            ],
        );
    }

    public function recordRejected(User $user, string $resultCode, ?OutboundSenderProfile $profile = null): void
    {
        $this->incrementMetric('rejected');
        $this->audit->write(
            'outbound.sender_profile_rejected',
            (string) $user->getKey(),
            $profile,
            null,
            null,
            ['result_code' => $resultCode],
        );
    }

    private function resolveDefaultForInbox(User $user, Inbox $inbox): ?OutboundSenderProfile
    {
        return OutboundSenderProfile::query()
            ->where('user_id', $user->getKey())
            ->where('inbox_id', $inbox->getKey())
            ->where('is_default', true)
            ->where('is_active', true)
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function clearProfileFields(?OutboundMessage $current): array
    {
        return [
            'sender_profile_id' => null,
            'from_display_name' => $current?->from_display_name,
            'reply_to_address' => $current?->reply_to_address,
            'reply_to_name' => $current?->reply_to_name,
            '_profile_for_signature' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function validateFields(User $user, array $input, Inbox $inbox, ?OutboundSenderProfile $existing = null): array
    {
        $maxName = (int) config('outbound.sender_profiles.max_name_length', 100);
        $existingName = $existing instanceof OutboundSenderProfile ? $existing->name : '';
        $name = trim((string) ($input['name'] ?? $existingName));
        if ($name === '') {
            throw new OutboundSendException('display_name_invalid', 'Profile name is required.', 422);
        }
        if (mb_strlen($name) > $maxName) {
            throw new OutboundSendException('display_name_invalid', 'Profile name is too long.', 422);
        }
        if (preg_match('/[\r\n\0]/', $name) === 1) {
            throw new OutboundSendException('display_name_invalid', 'Profile name contains invalid characters.', 422);
        }

        $displayName = array_key_exists('display_name', $input)
            ? ($input['display_name'] !== null && trim((string) $input['display_name']) !== '' ? $this->validateDisplayName((string) $input['display_name']) : null)
            : $existing?->display_name;

        $replyToAddress = array_key_exists('reply_to_address', $input)
            ? ($input['reply_to_address'] !== null && trim((string) $input['reply_to_address']) !== '' ? $this->headers->assertSafeEmail((string) $input['reply_to_address'], 'reply_to') : null)
            : $existing?->reply_to_address;

        if ($replyToAddress !== null) {
            $this->assertReplyToOwned($user, $replyToAddress);
        }

        $replyToName = array_key_exists('reply_to_name', $input)
            ? ($input['reply_to_name'] !== null && trim((string) $input['reply_to_name']) !== '' ? $this->sanitizeOptionalHeader((string) $input['reply_to_name'], 'reply_to_name', 255) : null)
            : $existing?->reply_to_name;

        $signatureText = array_key_exists('signature_text', $input)
            ? ($input['signature_text'] !== null ? trim((string) $input['signature_text']) : null)
            : $existing?->signature_text;
        $maxTextSig = (int) config('outbound.sender_profiles.max_signature_text_bytes', 10000);
        if ($signatureText !== null && strlen($signatureText) > $maxTextSig) {
            throw new OutboundSendException('signature_too_large', 'The text signature exceeds the maximum size.', 422);
        }

        $signatureHtml = array_key_exists('signature_html', $input)
            ? ($input['signature_html'] !== null ? $this->htmlSanitizer->sanitize((string) $input['signature_html']) : null)
            : $existing?->signature_html;
        $maxHtmlSig = (int) config('outbound.sender_profiles.max_signature_html_bytes', 20000);
        if ($signatureHtml !== null && strlen($signatureHtml) > $maxHtmlSig) {
            throw new OutboundSendException('signature_too_large', 'The HTML signature exceeds the maximum size.', 422);
        }

        $result = [
            'name' => $name,
            'display_name' => $displayName,
            'reply_to_address' => $replyToAddress,
            'reply_to_name' => $replyToName,
            'signature_text' => $signatureText !== null && $signatureText !== '' ? $signatureText : null,
            'signature_html' => $signatureHtml !== null && trim($signatureHtml) !== '' ? $signatureHtml : null,
        ];

        foreach (['include_on_send', 'include_on_reply', 'include_on_forward', 'is_active'] as $boolField) {
            if (array_key_exists($boolField, $input)) {
                $result[$boolField] = (bool) $input[$boolField];
            }
        }

        return $result;
    }

    private function validateDisplayName(string $value): ?string
    {
        if (preg_match('/[\r\n\0]/', $value) === 1) {
            throw new OutboundSendException('display_name_invalid', 'The display name contains invalid control characters.', 422);
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (mb_strlen($value) > 255) {
            throw new OutboundSendException('display_name_too_long', 'The display name is too long.', 422);
        }

        return $value;
    }

    private function sanitizeOptionalHeader(string $value, string $field, int $maxLength): string
    {
        if (preg_match('/[\r\n\0]/', $value) === 1) {
            throw new OutboundSendException('header_injection', "The {$field} contains invalid control characters.", 422);
        }
        $value = trim($value);
        if (mb_strlen($value) > $maxLength) {
            throw new OutboundSendException($field.'_too_long', "The {$field} exceeds the maximum length.", 422);
        }

        return $value;
    }

    private function shouldIncludeSignature(OutboundSenderProfile $profile, OutboundOperation $operation): bool
    {
        return match ($operation) {
            OutboundOperation::Send => $profile->include_on_send,
            OutboundOperation::Reply => $profile->include_on_reply,
            OutboundOperation::Forward => $profile->include_on_forward,
        };
    }

    private function insertSignatureBlock(?string $body, string $block, bool $isHtml): string
    {
        if ($body === null || trim($body) === '') {
            return ltrim($block);
        }

        $start = $isHtml ? self::HTML_SIG_START : self::TEXT_SIG_START;
        $end = $isHtml ? self::HTML_SIG_END : self::TEXT_SIG_END;

        if (str_contains($body, $start) && str_contains($body, $end)) {
            $pattern = '/'.preg_quote($start, '/').'.*?'.preg_quote($end, '/').'/s';

            return preg_replace($pattern, $block, $body, 1) ?? $body;
        }

        if ($isHtml) {
            return rtrim($body).'<br><br>'.$block;
        }

        return rtrim($body)."\n\n".$block;
    }

    private function assertFeatureEnabled(): void
    {
        if (! $this->enabled()) {
            throw new OutboundSendException('feature_disabled', 'Sender profiles are disabled.', 422);
        }
    }

    private function assertCommercialAccess(User $user): void
    {
        if ($this->entitlements->allows($user, 'outbound.sender_profiles')) {
            return;
        }

        $this->audit->write('commercial.sender_profile_denied', (string) $user->getKey(), $user, null, null, ['feature' => 'outbound.sender_profiles']);
        throw new OutboundSendException('feature_not_available', 'Your current plan does not include custom sender profiles.', 403);
    }

    private function assertUserActive(User $user): void
    {
        if ($user->trashed() || $user->status !== UserStatus::Active) {
            throw new OutboundSendException('user_inactive', 'The user account cannot manage sender profiles.', 403);
        }
    }

    private function assertOwnedInbox(User $user, string $inboxId): Inbox
    {
        $inbox = Inbox::query()->find($inboxId);
        if ($inbox === null || (string) $inbox->user_id !== (string) $user->getKey()) {
            throw new OutboundSendException('inbox_forbidden', 'The inbox does not belong to the authenticated user.', 404);
        }

        return $inbox;
    }

    private function assertProfileLimit(User $user, Inbox $inbox): void
    {
        $max = (int) config('outbound.sender_profiles.max_per_inbox', 20);
        $count = OutboundSenderProfile::query()
            ->where('user_id', $user->getKey())
            ->where('inbox_id', $inbox->getKey())
            ->count();

        if ($count >= $max) {
            throw new OutboundSendException('profile_limit_exceeded', 'Maximum sender profiles per inbox exceeded.', 422);
        }
    }

    private function assertUniqueName(User $user, Inbox $inbox, string $name, ?string $exceptId = null): void
    {
        $exists = OutboundSenderProfile::query()
            ->where('user_id', $user->getKey())
            ->where('inbox_id', $inbox->getKey())
            ->where('name', $name)
            ->when($exceptId !== null, fn (Builder $q): Builder => $q->where('id', '!=', $exceptId))
            ->exists();

        if ($exists) {
            throw new OutboundSendException('profile_conflict', 'A sender profile with this name already exists for the inbox.', 409);
        }
    }

    private function clearDefault(User $user, Inbox $inbox, ?string $exceptId = null): void
    {
        OutboundSenderProfile::query()
            ->where('user_id', $user->getKey())
            ->where('inbox_id', $inbox->getKey())
            ->where('is_default', true)
            ->when($exceptId !== null, fn (Builder $q): Builder => $q->where('id', '!=', $exceptId))
            ->update(['is_default' => false]);
    }

    /** @param array<string, mixed> $safe */
    private function auditProfile(User $user, string $action, OutboundSenderProfile $profile, array $safe): void
    {
        $this->audit->write(
            $action,
            (string) $user->getKey(),
            $profile,
            null,
            null,
            $safe,
        );
    }

    private function incrementMetric(string $event): void
    {
        Cache::increment('outbound.metrics.sender_profile_'.$event);
    }
}
