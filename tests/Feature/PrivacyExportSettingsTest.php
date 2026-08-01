<?php

declare(strict_types=1);

use App\Enums\PrivacyExportStatus;
use App\Jobs\Settings\ProcessPrivacyExportJob;
use App\Models\User;
use App\Services\Settings\PrivacyPreferenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'settings.privacy.export.enabled' => true,
        'settings.privacy.export.disk' => 'local',
        'settings.privacy.export.rate_limit_hours' => 24,
    ]);
    Storage::fake('local');
    Queue::fake();
});

it('rate limits duplicate privacy export requests', function (): void {
    $user = User::factory()->create(['password' => Hash::make('SettingsPass1!')]);
    $service = app(PrivacyPreferenceService::class);

    $service->requestExport($user, true);

    expect(fn () => $service->requestExport($user, true))
        ->toThrow(ValidationException::class);
});

it('allows owner download of ready exports only', function (): void {
    $owner = User::factory()->create(['password' => Hash::make('SettingsPass1!')]);
    $other = User::factory()->create(['password' => Hash::make('SettingsPass1!')]);
    $service = app(PrivacyPreferenceService::class);

    $export = $service->requestExport($owner, true);
    (new ProcessPrivacyExportJob((string) $export->getKey()))->handle($service);
    $export = $export->fresh();

    expect($export->status)->toBe(PrivacyExportStatus::Ready);

    expect(fn () => $service->download($other, $export))->toThrow(HttpException::class);
});
