<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Policies\AttachmentVisibilityPolicy;
use App\Services\Inbound\InboundHtmlSanitizer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Email */
final class EmailResource extends JsonResource
{
    /**
     * @param  mixed  $resource
     */
    protected static function newCollection($resource): EmailCollection
    {
        return new EmailCollection($resource);
    }

    public function toArray(Request $request): array
    {
        $sanitizer = app(InboundHtmlSanitizer::class);
        $visibility = app(AttachmentVisibilityPolicy::class);
        $actor = $request->attributes->get('apiKeyOwner');

        return [
            'id' => $this->id,
            'inbox_id' => $this->inbox_id,
            'message_id' => $this->message_id,
            'sender' => [
                'name' => $this->sender_name,
                'email' => $this->sender_email,
            ],
            'recipients' => array_values(array_filter([
                $this->recipient_email,
            ])),
            'subject' => $this->subject,
            'received_at' => $this->received_at,
            'text_body' => $this->whenLoaded('body', fn () => $this->body?->text_body),
            'html_body' => $this->whenLoaded('body', fn () => $sanitizer->sanitize($this->body?->html_body)),
            'attachments' => $this->whenLoaded('attachments', function () use ($visibility, $actor) {
                return $this->attachments
                    ->filter(fn ($attachment): bool => $visibility->view($actor, $attachment))
                    ->values()
                    ->map(fn ($attachment): array => [
                        'id' => $attachment->id,
                        'original_filename' => $attachment->original_filename,
                        'mime_type' => $attachment->mime_type,
                        'extension' => $attachment->extension,
                        'size_bytes' => $attachment->size_bytes,
                        'scan_status' => $attachment->scan_status?->value,
                    ])
                    ->all();
            }),
        ];
    }
}
