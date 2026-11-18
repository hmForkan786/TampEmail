<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Enums\AffiliateAttributionStatus;
use App\Enums\AffiliateCommissionEntryStatus;
use App\Enums\AffiliateCommissionEntryType;
use App\Enums\AffiliateConversionStatus;
use App\Enums\AffiliateProfileStatus;
use App\Enums\AffiliateWithdrawalStatus;
use App\Models\AffiliateAttribution;
use App\Models\AffiliateCommissionEntry;
use App\Models\AffiliateConversion;
use App\Models\AffiliateProfile;
use App\Models\AffiliateWithdrawal;
use App\Models\User;
use App\Services\Affiliates\AffiliateHealthCheckService;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

final class AffiliateControlPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Affiliates';

    protected static ?int $navigationSort = 70;

    protected static ?string $title = 'Affiliate Control';

    protected string $view = 'filament.admin.pages.affiliate-control';

    /** @var array<string, mixed> */
    public array $health = [];

    /** @var array<string, mixed> */
    public array $settings = [];

    /** @var array<string, mixed> */
    public array $analytics = [];

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
        $this->health = app(AffiliateHealthCheckService::class)->check();
        $this->settings = $this->safeSettings();
        $this->analytics = $this->analyticsSummary();
    }

    /** @return array<string, mixed> */
    private function safeSettings(): array
    {
        $config = (array) config('affiliates');
        unset($config['hash_key']);

        return $config;
    }

    /** @return array<string, mixed> */
    private function analyticsSummary(): array
    {
        return [
            'profiles_total' => AffiliateProfile::query()->count(),
            'profiles_active' => AffiliateProfile::query()->where('status', AffiliateProfileStatus::Active->value)->count(),
            'profiles_pending' => AffiliateProfile::query()->where('status', AffiliateProfileStatus::Pending->value)->count(),
            'profiles_suspended' => AffiliateProfile::query()->where('status', AffiliateProfileStatus::Suspended->value)->count(),
            'attributions_total' => AffiliateAttribution::query()->count(),
            'attributions_active' => AffiliateAttribution::query()->where('status', AffiliateAttributionStatus::Active->value)->count(),
            'conversions_total' => AffiliateConversion::query()->count(),
            'conversions_approved' => AffiliateConversion::query()->where('status', AffiliateConversionStatus::Approved->value)->count(),
            'conversions_pending' => AffiliateConversion::query()->where('status', AffiliateConversionStatus::Pending->value)->count(),
            'commission_pending_entries' => AffiliateCommissionEntry::query()->where('entry_type', AffiliateCommissionEntryType::Commission->value)->where('status', AffiliateCommissionEntryStatus::Pending->value)->count(),
            'commission_available_entries' => AffiliateCommissionEntry::query()->where('entry_type', AffiliateCommissionEntryType::Commission->value)->where('status', AffiliateCommissionEntryStatus::Available->value)->count(),
            'withdrawals_open' => AffiliateWithdrawal::query()->whereIn('status', [
                AffiliateWithdrawalStatus::Requested->value,
                AffiliateWithdrawalStatus::UnderReview->value,
                AffiliateWithdrawalStatus::Approved->value,
                AffiliateWithdrawalStatus::Processing->value,
            ])->count(),
            'withdrawals_paid' => AffiliateWithdrawal::query()->where('status', AffiliateWithdrawalStatus::Paid->value)->count(),
        ];
    }
}
