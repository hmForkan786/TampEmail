<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Models\OutboundMessage;
use App\Models\User;
use App\Services\Outbound\OutboundMessageTimelineBuilder;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Validator;
use UnitEnum;

/**
 * Admin lookup for a single outbound message's delivery timeline.
 *
 * Shows more detail than the user-facing API (provider name, sanitized
 * failure code, safe reconciliation category) but still never exposes
 * secrets, raw provider payloads, message bodies, recipients, or BCC —
 * the underlying {@see OutboundMessageTimelineBuilder} never has access to
 * those fields in the first place.
 */
final class OutboundMessageTimelinePage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 31;

    protected static ?string $title = 'Outbound Message Timeline';

    protected string $view = 'filament.admin.pages.outbound-message-timeline';

    public ?string $message_id = null;

    /**
     * @var list<array<string, mixed>>
     */
    public array $timeline = [];

    /**
     * @var array<string, mixed>|null
     */
    public ?array $summary = null;

    public ?string $lookupError = null;

    public static function canAccess(): bool
    {
        $actor = auth()->user();

        return $actor instanceof User && $actor->isPlatformAdmin();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return self::canAccess();
    }

    public static function getNavigationLabel(): string
    {
        return 'Outbound Message Timeline';
    }

    public function loadTimeline(): void
    {
        $this->summary = null;
        $this->timeline = [];
        $this->lookupError = null;

        $validator = Validator::make(['message_id' => $this->message_id], [
            'message_id' => ['required', 'uuid'],
        ]);

        if ($validator->fails()) {
            $this->lookupError = 'Enter a valid outbound message ID.';

            return;
        }

        $message = OutboundMessage::query()->find($this->message_id);

        if ($message === null) {
            $this->lookupError = 'Outbound message not found.';
            Notification::make()->title('Outbound message not found')->danger()->send();

            return;
        }

        $this->summary = [
            'id' => (string) $message->getKey(),
            'operation' => $message->operation->value,
            'state' => $message->state->value,
            'attempt_count' => $message->attempt_count,
            'provider' => $message->provider,
            'reconciliation_note' => $message->reconciliation_note,
            'created_at' => $message->created_at?->toIso8601String(),
        ];

        $this->timeline = app(OutboundMessageTimelineBuilder::class)->build($message, admin: true);
    }
}
