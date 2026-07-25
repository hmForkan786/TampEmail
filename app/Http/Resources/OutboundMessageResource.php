<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\OutboundMessage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin OutboundMessage */
final class OutboundMessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $includeBcc = true;

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
            'bcc' => $this->when($includeBcc, $this->bcc_recipients ?? []),
            'subject' => $this->subject,
            'text_body' => $this->text_body,
            'html_body' => $this->html_body,
            'attempt_count' => $this->attempt_count,
            'provider' => $this->provider,
            'queued_at' => $this->queued_at,
            'sending_at' => $this->sending_at,
            'sent_at' => $this->sent_at,
            'failed_at' => $this->failed_at,
            'cancelled_at' => $this->cancelled_at,
            'failure_code' => $this->failure_code,
            'failure_message' => $this->failure_message,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
