<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Enums\AffiliateFraudDecision;
use App\Models\AffiliateFraudFlag;
use App\Models\User;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Read-focused triage list for affiliate fraud signals that were routed to
 * manual review (i.e. not auto-allowed or auto-rejected). Marking a flag
 * reviewed only records who looked at it; it never changes any commission
 * or conversion outcome, which continues to be governed by the existing
 * affiliate services.
 */
final class AffiliateFraudReviewPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static string|UnitEnum|null $navigationGroup = 'Affiliates';

    protected static ?int $navigationSort = 80;

    protected static ?string $title = 'Fraud Review';

    protected string $view = 'filament.admin.pages.affiliate-fraud-review';

    /** @var array<int, AffiliateFraudFlag> */
    public array $flags = [];

    public static function canAccess(): bool
    {
        $actor = auth()->user();

        return $actor instanceof User && $actor->isPlatformAdmin();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return self::canAccess();
    }

    public function mount(): void
    {
        $this->refreshState();
    }

    public function refreshState(): void
    {
        $this->flags = AffiliateFraudFlag::query()
            ->where('decision', AffiliateFraudDecision::ManualReview->value)
            ->whereNull('reviewed_at')
            ->with('profile.user')
            ->latest('created_at')
            ->limit(50)
            ->get()
            ->all();
    }

    public function markReviewed(string $flagId): void
    {
        $actor = auth()->user();

        if (! $actor instanceof User || ! $actor->isPlatformAdmin()) {
            abort(403);
        }

        AffiliateFraudFlag::query()->whereKey($flagId)->update(['reviewed_at' => now()]);
        $this->refreshState();

        Notification::make()->title('Fraud flag marked reviewed')->success()->send();
    }
}
