<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

it('updates profile settings with allowlisted locale and timezone', function (): void {
    $user = User::factory()->create([
        'name' => 'Profile User',
        'locale' => 'en',
        'timezone' => 'UTC',
        'password' => Hash::make('SettingsPass1!'),
    ]);

    $this->actingAs($user)
        ->put(route('settings.profile.update'), [
            'name' => 'Updated Profile',
            'locale' => 'bn',
            'timezone' => 'Asia/Dhaka',
        ])
        ->assertRedirect();

    expect($user->fresh()->name)->toBe('Updated Profile')
        ->and($user->fresh()->locale)->toBe('bn');
});

it('forbids privileged profile field injection', function (): void {
    $user = User::factory()->create([
        'locale' => 'en',
        'timezone' => 'UTC',
        'password' => Hash::make('SettingsPass1!'),
    ]);

    $this->actingAs($user)
        ->put(route('settings.profile.update'), [
            'name' => 'Still Me',
            'locale' => 'en',
            'timezone' => 'UTC',
            'status' => 'closed',
            'platform_role' => 'admin',
        ])
        ->assertSessionHasErrors(['status', 'platform_role']);
});
