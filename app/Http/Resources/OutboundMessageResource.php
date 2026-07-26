<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Attachment;
use App\Models\OutboundMessage;
use App\Services\Inbound\InboundHtmlSanitizer;
use App\Services\Outbound\OutboundFailureCategoryMapper;
use App\Services\Outbound\OutboundMessageAccessService;
use App\Services\Outbound\OutboundScheduleTimezone;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Owner-facing outbound message representation.
 *
 * Never exposes `metadata`, raw provider identity, or reconciliation
 * fields. BCC is included because this resource is only ever returned to
 * the owning user (API key owner or authenticated web session), matching
 * the existing API contract.
 *
 * @mixin OutboundMessage
 */
final class OutboundMessageResource extends JsonResource
{
    /**
     * @param  mixed  $resource
     */
    protected static function newCollection($resource): OutboundMessageCollection
    {
        return new OutboundMessageCollection($resource);
    }

    public function toArray(Request $request): array
    {
        $sanitizer = app(InboundHtmlSanitizer::class);
        $categories = app(OutboundFailureCategoryMapper::class);
        $access = app(OutboundMessageAccessService::class);
        $timezones = app(OutboundScheduleTimezone::class);

        $scheduledLocalAt = null;
        if ($this->scheduled_at !== null && is_string($this->scheduled_timezone) && $this->scheduled_timezone !== '') {
            $scheduledLocalAt = $timezones->formatLocal($this->scheduled_at->toImmutable(), $this->scheduled_timezone);
        }

        return [
            'id' => $this->id,
            'inbox_id' => $this->inbox_id,
            'source_email_id' => $this->source_email_id,
            'operation' => $this->operation?->value,
            'state' => $this->state?->value,
            'draft_version' => $this->when($this->state?->value === 'draft', $this->draft_version),
            'schedule_version' => $this->when(
                $this->state?->value === 'scheduled' || (int) $this->schedule_version > 0,
                (int) $this->schedule_version,
            ),
            'scheduled_at' => $this->when($this->scheduled_at !== null, fn () => $this->scheduled_at?->toIso8601String()),
            'scheduled_timezone' => $this->when($this->scheduled_timezone !== null, $this->scheduled_timezone),
            'scheduled_local_at' => $this->when($scheduledLocalAt !== null, $scheduledLocalAt),
            'from' => [
                'email' => $this->from_address,
                'name' => $this->from_display_name,
            ],
            'reply_to' => $this->when(
                $this->reply_to_address !== null,
                fn (): array => [
                    'email' => $this->reply_to_address,
                    'name' => $this->reply_to_name,
                ],
            ),
            'sender_profile_id' => $this->when($this->sender_profile_id !== null, $this->sender_profile_id),
            'to' => $this->to_recipients ?? [],
            'cc' => $this->cc_recipients ?? [],
            'bcc' => $this->bcc_recipients ?? [],
            'subject' => $this->subject,
            'text_body' => $this->text_body,
            'html_body' => $sanitizer->sanitize($this->html_body),
            'attempt_count' => $this->attempt_count,
            'attachment_count' => count($this->attachment_ids ?? []),
            'attachments' => $this->whenLoaded(
                'safeAttachments',
                fn () => $this->safeAttachments
                    ->map(fn (Attachment $attachment): array => [
                        'id' => $attachment->id,
                        'original_filename' => $attachment->original_filename,
                        'size_bytes' => $attachment->size_bytes,
                        'mime_type' => $attachment->mime_type,
                    ])
                    ->values()
                    ->all(),
            ),
            'queued_at' => $this->queued_at,
            'sending_at' => $this->sending_at,
            'sent_at' => $this->sent_at,
            'delivered_at' => $this->delivered_at,
            'failed_at' => $this->failed_at,
            'cancelled_at' => $this->cancelled_at,
            'draft_submitted_at' => $this->when($this->draft_submitted_at !== null, $this->draft_submitted_at),
            'failure_code' => $this->failure_code,
            'failure_category' => $this->failure_code !== null ? $categories->userSafeCategory($this->failure_code) : null,
            'can_cancel' => $access->canCancel($this->resource),
            'can_retry' => $access->canRetry($this->resource),
            'can_delete' => $access->canDelete($this->resource),
            'can_reschedule' => $access->canReschedule($this->resource),
            'can_unschedule' => $access->canUnschedule($this->resource),
            'can_send_now' => $access->canSendNow($this->resource),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
