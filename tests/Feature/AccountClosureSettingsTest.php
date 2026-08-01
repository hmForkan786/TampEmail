<?php

declare(strict_types=1);

use App\Enums\UserStatus;
use App\Models\User;
use App\Services\Settings\UserSettingsSummaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('requires confirmation phrase for settings account closure', function (): void {
    $user = User::factory()->create(['password' => Hash::make('SettingsPass1!')]);

    expect(fn () => app(UserSettingsSummaryService::class)->closeAccount(
        $user,
        'SettingsPass1!',
        'wrong phrase',
        'test',
    ))->toThrow(ValidationException::class);

    expect($user->fresh()->status)->not->toBe(UserStatus::Closed);
});
