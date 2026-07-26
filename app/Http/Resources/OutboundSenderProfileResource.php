<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\OutboundSenderProfile;
use App\Services\Inbound\InboundHtmlSanitizer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin OutboundSenderProfile
 */
final class OutboundSenderProfileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $sanitizer = app(InboundHtmlSanitizer::class);

        return [
            'id' => $this->id,
            'inbox_id' => $this->inbox_id,
            'name' => $this->name,
            'display_name' => $this->display_name,
            'reply_to_address' => $this->reply_to_address,
            'reply_to_name' => $this->reply_to_name,
            'signature_text' => $this->signature_text,
            'signature_html' => $sanitizer->sanitize($this->signature_html),
            'include_on_send' => $this->include_on_send,
            'include_on_reply' => $this->include_on_reply,
            'include_on_forward' => $this->include_on_forward,
            'is_default' => $this->is_default,
            'is_active' => $this->is_active,
            'version' => $this->version,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
