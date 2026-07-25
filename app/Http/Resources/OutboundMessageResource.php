<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Attachment;
use App\Models\OutboundMessage;
use App\Services\Inbound\InboundHtmlSanitizer;
use App\Services\Outbound\OutboundFailureCategoryMapper;
use App\Services\Outbound\OutboundMessageAccessService;
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

        return [
            'id' => $this->id,
            'inbox_id' => $this->inbox_id,
            'source_email_id' => $this->source_email_id,
            'operation' => $this->operation?->value,
            'state' => $this->state?->value,
            'from' => [
                'email' => $this->from_address,
                'name' => $this->from_display_name,
            ],
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
            'failure_code' => $this->failure_code,
            'failure_category' => $this->failure_code !== null ? $categories->userSafeCategory($this->failure_code) : null,
            'can_cancel' => $access->canCancel($this->resource),
            'can_retry' => $access->canRetry($this->resource),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
