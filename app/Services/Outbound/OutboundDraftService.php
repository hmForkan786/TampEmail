<?php

declare(strict_types=1);

namespace App\Services\Outbound;

use App\Enums\OutboundMessageState;
use App\Enums\OutboundOperation;
use App\Enums\UserStatus;
use App\Exceptions\OutboundSendException;
use App\Jobs\DeliverOutboundMessageJob;
use App\Models\Email;
use App\Models\Inbox;
use App\Models\OutboundMessage;
use App\Models\OutboundSenderProfile;
use App\Models\User;
use App\Services\Audit\AuditLogWriter;
use App\Services\Inbound\InboundHtmlSanitizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Shared API/web draft domain service. Drafts are outbound rows until atomically queued. */
final class OutboundDraftService
{
    public function __construct(
        private readonly OutboundAuthorizationService $authorization, private readonly OutboundRecipientValidator $recipients,
        private readonly OutboundContentValidator $content, private readonly OutboundReplyRecipientResolver $replyRecipients,
        private readonly OutboundSubjectHelper $subjects, private readonly OutboundThreadingHeaders $threading,
        private readonly OutboundForwardContextBuilder $forwardContext, private readonly OutboundAttachmentSelector $attachments,
        private readonly OutboundSuppressionService $suppressions, private readonly OutboundRateLimiter $rateLimiter,
        private readonly OutboundLaunchControlService $launchControl, private readonly OutboundUsageService $usage,
        private readonly OutboundSenderProfileService $senderProfiles,
        private readonly AuditLogWriter $audit,
    ) {}

    /** @param array<string,mixed> $input */
    public function create(User $user, array $input): OutboundMessage
    {
        $values = $this->draftValues($user, $input);
        $draft = OutboundMessage::query()->create($values + [
            'user_id' => $user->getKey(), 'state' => OutboundMessageState::Draft, 'draft_version' => 1,
            'idempotency_key' => 'draft:'.Str::uuid(), 'request_fingerprint' => hash('sha256', (string) Str::uuid()), 'attempt_count' => 0,
        ]);
        $this->audit($user, 'outbound.draft_created', $draft, ['version' => 1]);

        return $draft;
    }

    /** @param array<string,mixed> $input */
    public function update(User $user, string $id, array $input): OutboundMessage
    {
        return DB::transaction(function () use ($user, $id, $input): OutboundMessage {
            $draft = $this->lockedOwned($user, $id);
            $this->assertVersion($draft, (int) $input['version']);
            $draft->fill($this->draftValues($user, $input, $draft));
            $draft->draft_version++;
            $draft->save();
            $this->audit($user, 'outbound.draft_updated', $draft, ['version' => $draft->draft_version]);

            return $draft;
        });
    }

    public function delete(User $user, string $id, ?int $version): void
    {
        DB::transaction(function () use ($user, $id, $version): void {
            $draft = OutboundMessage::query()->whereKey($id)->where('user_id', $user->getKey())->lockForUpdate()->first();
            if ($draft === null || $draft->state !== OutboundMessageState::Draft || $draft->draft_deleted_at !== null) {
                return;
            }
            if ($version !== null) {
                $this->assertVersion($draft, $version);
            }
            $draft->forceFill(['draft_deleted_at' => now()])->save();
            $this->audit($user, 'outbound.draft_deleted', $draft, ['version' => $draft->draft_version]);
        });
    }

    public function submit(User $user, string $id, int $version, ?string $apiKeyId = null): OutboundMessage
    {
        $claimed = false;
        $message = DB::transaction(function () use ($user, $id, $version, $apiKeyId, &$claimed): OutboundMessage {
            $draft = OutboundMessage::query()->whereKey($id)->where('user_id', $user->getKey())->lockForUpdate()->first();
            if ($draft === null || $draft->draft_deleted_at !== null) {
                throw new OutboundSendException('draft_not_found', 'Draft not found.', 404);
            }
            if ($draft->state === OutboundMessageState::Queued) {
                return $draft;
            }
            if ($draft->state !== OutboundMessageState::Draft) {
                throw new OutboundSendException('already_submitted', 'This draft is no longer editable.', 409);
            }
            $this->assertVersion($draft, $version);
            $prepared = $this->prepareSendableContent($draft, $user, $apiKeyId);
            $this->rateLimiter->assertWithinLimits($user, [...$prepared['to'], ...$prepared['cc'], ...$prepared['bcc']], $prepared['attachment_bytes']);
            $draft->forceFill([
                'state' => OutboundMessageState::Queued,
                'to_recipients' => $prepared['to'],
                'cc_recipients' => $prepared['cc'],
                'bcc_recipients' => $prepared['bcc'],
                'subject' => $prepared['subject'],
                'text_body' => $prepared['text_body'],
                'html_body' => $prepared['html_body'],
                'from_display_name' => $prepared['from_display_name'],
                'reply_to_address' => $prepared['reply_to_address'],
                'reply_to_name' => $prepared['reply_to_name'],
                'attachment_ids' => $prepared['attachment_ids'],
                'in_reply_to' => $prepared['in_reply_to'],
                'references' => $prepared['references'],
                'request_fingerprint' => $prepared['request_fingerprint'],
                'queued_at' => now(),
                'draft_submitted_at' => now(),
                'is_canary' => $prepared['is_canary'],
            ])->save();
            $this->usage->reserve($user, $draft, $draft->idempotency_key, $prepared['attachment_bytes']);
            $this->audit($user, 'outbound.draft_submitted', $draft, ['version' => $draft->draft_version]);
            $claimed = true;

            return $draft;
        });
        if ($claimed) {
            DeliverOutboundMessageJob::dispatch((string) $message->getKey());
        }

        return $message;
    }

    /**
     * Validates draft content, recipients, suppressions, and auth without
     * rate-limiting, usage reservation, or state transition.
     *
     * @return array{
     *     to: list<string>,
     *     cc: list<string>,
     *     bcc: list<string>,
     *     subject: ?string,
     *     text_body: ?string,
     *     html_body: ?string,
     *     attachment_ids: list<string>,
     *     attachment_bytes: int,
     *     inbox: Inbox,
     *     request_fingerprint: string,
     *     is_canary: bool,
     *     in_reply_to: ?string,
     *     references: ?string,
     *     from_display_name: ?string,
     *     reply_to_address: ?string,
     *     reply_to_name: ?string,
     * }
     */
    public function prepareSendableContent(OutboundMessage $draft, User $user, ?string $apiKeyId = null): array
    {
        if ($draft->draft_deleted_at !== null) {
            throw new OutboundSendException('draft_not_found', 'Draft not found.', 404);
        }

        $inbox = Inbox::query()->with('domain')->find($draft->inbox_id);
        if ($inbox === null) {
            throw new OutboundSendException('inbox_not_found', 'The inbox was not found.', 404);
        }

        $this->authorization->assertCanSend($user, $inbox, $draft->operation, $apiKeyId);
        $source = in_array($draft->operation, [OutboundOperation::Reply, OutboundOperation::Forward], true)
            ? $this->source($user, $draft)
            : null;
        $to = $draft->to_recipients ?? [];
        $cc = $draft->cc_recipients ?? [];
        $bcc = $draft->bcc_recipients ?? [];
        $subject = $draft->subject;
        $text = $draft->text_body;
        $html = $draft->html_body;
        $attachmentIds = $draft->attachment_ids ?? [];
        $attachmentBytes = 0;
        $inReplyTo = $draft->in_reply_to;
        $references = $draft->references;

        if ($draft->operation === OutboundOperation::Reply) {
            $to = [$this->replyRecipients->resolve($source)];
            $bcc = [];
            $set = $this->recipients->validate($to, $cc, []);
            $to = $set['to'];
            $cc = $set['cc'];
            $subject = $this->subjects->replySubject($source->subject, $subject);
            $headers = $this->threading->forReply($source);
            $inReplyTo = $headers['in_reply_to'];
            $references = $headers['references'];
        } elseif ($draft->operation === OutboundOperation::Forward) {
            $set = $this->recipients->validate($to, $cc, $bcc);
            $to = $set['to'];
            $cc = $set['cc'];
            $bcc = $set['bcc'];
            $selected = $this->attachments->selectForForward($source, $attachmentIds);
            $attachmentIds = array_map(fn ($a): string => (string) $a->getKey(), $selected);
            $attachmentBytes = array_sum(array_map(fn ($a): int => (int) $a->size_bytes, $selected));
            $subject = $this->subjects->forwardSubject($source->subject, $subject);
            $text = $this->forwardContext->buildText($text, $source);
            $html = $html !== null ? $this->forwardContext->buildHtml($html, $source) : null;
        } else {
            $set = $this->recipients->validate($to, $cc, $bcc);
            $to = $set['to'];
            $cc = $set['cc'];
            $bcc = $set['bcc'];
        }

        $identity = $this->senderProfiles->resolveForSend($draft, $user, $inbox);
        $subject = $draft->subject;

        $content = $this->content->validate($subject, $identity['text_body'], $identity['html_body'], $identity['from_display_name']);
        $this->suppressions->assertRecipientsAllowed([...$to, ...$cc, ...$bcc], $user);

        return [
            'to' => $to,
            'cc' => $cc,
            'bcc' => $bcc,
            'subject' => $content['subject'],
            'text_body' => $content['text_body'],
            'html_body' => $content['html_body'],
            'from_display_name' => $content['from_display_name'],
            'reply_to_address' => $identity['reply_to_address'],
            'reply_to_name' => $identity['reply_to_name'],
            'attachment_ids' => $attachmentIds,
            'attachment_bytes' => $attachmentBytes,
            'inbox' => $inbox,
            'request_fingerprint' => hash('sha256', json_encode([
                $draft->operation->value,
                $to,
                $cc,
                $bcc,
                $content,
                $identity['reply_to_address'],
                $identity['reply_to_name'],
                $attachmentIds,
            ], JSON_THROW_ON_ERROR)),
            'is_canary' => $this->launchControl->isCanary($user, $inbox, $apiKeyId),
            'in_reply_to' => $inReplyTo,
            'references' => $references,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function draftValues(User $user, array $input, ?OutboundMessage $current = null): array
    {
        if ($user->trashed() || $user->status !== UserStatus::Active) {
            throw new OutboundSendException('user_inactive', 'The user account cannot create drafts.', 403);
        }
        $inbox = Inbox::query()->find($input['inbox_id'] ?? $current?->inbox_id);
        if ($inbox === null || (string) $inbox->user_id !== (string) $user->getKey()) {
            throw new OutboundSendException('inbox_forbidden', 'The inbox does not belong to the authenticated user.', 404);
        }
        $operation = OutboundOperation::from($input['operation'] ?? $current?->operation->value ?? 'send');
        $sourceId = $input['source_email_id'] ?? $current?->source_email_id;
        if (in_array($operation, [OutboundOperation::Reply, OutboundOperation::Forward], true) && $sourceId === null) {
            throw new OutboundSendException('source_required', 'A source email is required for this draft.', 422);
        }
        if ($sourceId !== null) {
            $this->sourceById($user, (string) $sourceId, $inbox);
        }
        $to = array_key_exists('to', $input) ? $input['to'] : ($current !== null ? ($current->to_recipients ?? []) : []);
        $cc = array_key_exists('cc', $input) ? $input['cc'] : ($current !== null ? ($current->cc_recipients ?? []) : []);
        $bcc = array_key_exists('bcc', $input) ? $input['bcc'] : ($current !== null ? ($current->bcc_recipients ?? []) : []);
        if ($to || $cc || $bcc) {
            $this->recipients->validate($to, $cc, $bcc);
        }
        $attachmentIds = array_key_exists('attachment_ids', $input) ? $input['attachment_ids'] : ($current !== null ? ($current->attachment_ids ?? []) : []);
        if ($attachmentIds !== [] && $operation !== OutboundOperation::Forward) {
            throw new OutboundSendException('attachments_unsupported', 'Attachments are only supported for forward drafts.', 422);
        }
        if ($operation === OutboundOperation::Forward) {
            $this->attachments->selectForForward($this->sourceById($user, (string) $sourceId, $inbox), $attachmentIds, false);
        }
        foreach (['subject'] as $field) {
            if (array_key_exists($field, $input) && preg_match('/[\r\n\0]/', (string) $input[$field])) {
                throw new OutboundSendException('header_injection', 'The draft contains invalid header characters.', 422);
            }
        }
        $html = array_key_exists('html_body', $input) ? app(InboundHtmlSanitizer::class)->sanitize($input['html_body']) : $current?->html_body;
        $text = $input['text_body'] ?? $current?->text_body;
        if ($text !== null && strlen($text) > (int) config('outbound.max_text_body_bytes', 102400)) {
            throw new OutboundSendException('text_body_too_large', 'The text body exceeds the maximum size.', 422);
        }
        if ($html !== null && strlen($html) > (int) config('outbound.max_html_body_bytes', 204800)) {
            throw new OutboundSendException('html_body_too_large', 'The HTML body exceeds the maximum size.', 422);
        }

        $profileFields = $this->senderProfiles->resolveDraftProfileFields($user, $inbox, $operation, $input, $current);
        /** @var OutboundSenderProfile|null $profileForSignature */
        $profileForSignature = $profileFields['_profile_for_signature'] ?? null;
        unset($profileFields['_profile_for_signature']);

        $signed = $this->senderProfiles->applySignatureToBodies($text, $html, $profileForSignature, $operation);
        if ($profileForSignature !== null) {
            $this->senderProfiles->recordApplied($user, $profileForSignature, $inbox);
        }

        return array_merge(
            [
                'inbox_id' => $inbox->getKey(),
                'source_email_id' => $sourceId,
                'operation' => $operation,
                'from_address' => $inbox->full_address,
                'to_recipients' => $to,
                'cc_recipients' => $cc ?: null,
                'bcc_recipients' => $bcc ?: null,
                'subject' => $input['subject'] ?? $current?->subject,
                'text_body' => $signed['text_body'],
                'html_body' => $signed['html_body'],
                'attachment_ids' => $attachmentIds ?: null,
            ],
            $profileFields,
        );
    }

    private function lockedOwned(User $user, string $id): OutboundMessage
    {
        $draft = OutboundMessage::query()->whereKey($id)->where('user_id', $user->getKey())->where('state', OutboundMessageState::Draft)->whereNull('draft_deleted_at')->lockForUpdate()->first();
        if ($draft === null) {
            throw new OutboundSendException('draft_not_found', 'Draft not found.', 404);
        }

        return $draft;
    }

    private function assertVersion(OutboundMessage $draft, int $version): void
    {
        if ($draft->draft_version !== $version) {
            throw new OutboundSendException('draft_conflict', 'This draft has changed. Refresh and try again.', 409);
        }
    }

    private function source(User $user, OutboundMessage $draft): Email
    {
        return $this->sourceById($user, (string) $draft->source_email_id, Inbox::query()->findOrFail($draft->inbox_id));
    }

    private function sourceById(User $user, string $id, Inbox $inbox): Email
    {
        $email = Email::query()->with(['inbox', 'body'])->find($id);
        if ($email === null || $email->trashed() || (string) $email->inbox_id !== (string) $inbox->getKey() || (string) $inbox->user_id !== (string) $user->getKey()) {
            throw new OutboundSendException('email_not_found', 'The original email was not found.', 404);
        }

        return $email;
    }

    /** @param array<string,mixed> $safe */
    private function audit(User $user, string $action, OutboundMessage $draft, array $safe): void
    {
        $this->audit->write($action, (string) $user->getKey(), $draft, null, null, $safe + ['operation' => $draft->operation->value, 'recipient_count' => $draft->recipientCount(), 'attachment_count' => count($draft->attachment_ids ?? [])]);
    }
}
