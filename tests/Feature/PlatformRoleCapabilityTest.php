<?php

use App\Enums\PlatformRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('defaults existing users to the user platform role', function (): void {
    $user = User::factory()->create();

    expect($user->platform_role)->toBe(PlatformRole::User)
        ->and($user->fresh()->platform_role)->toBe(PlatformRole::User);

    // Database default also applies when the column is omitted from the insert.
    $id = (string) \Illuminate\Support\Str::uuid();
    \Illuminate\Support\Facades\DB::table('users')->insert([
        'id' => $id,
        'name' => 'Default Role User',
        'email' => 'default-role@example.test',
        'password' => 'secret',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(User::query()->findOrFail($id)->platform_role)->toBe(PlatformRole::User);
});

it('does not treat ordinary users as operators or admins', function (): void {
    $user = User::factory()->create(['platform_role' => PlatformRole::User]);

    expect($user->hasActivePlatformCapability())->toBeFalse()
        ->and($user->isPlatformOperator())->toBeFalse()
        ->and($user->isPlatformAdmin())->toBeFalse();
});

it('verifies an active operator', function (): void {
    $user = User::factory()->create([
        'platform_role' => PlatformRole::Operator,
        'status' => UserStatus::Active,
    ]);

    expect($user->hasActivePlatformCapability())->toBeTrue()
        ->and($user->isPlatformOperator())->toBeTrue()
        ->and($user->isPlatformAdmin())->toBeFalse();
});

it('verifies an active admin and grants operator capability', function (): void {
    $user = User::factory()->create([
        'platform_role' => PlatformRole::Admin,
        'status' => UserStatus::Active,
    ]);

    expect($user->hasActivePlatformCapability())->toBeTrue()
        ->and($user->isPlatformOperator())->toBeTrue()
        ->and($user->isPlatformAdmin())->toBeTrue();
});

it('rejects pending, suspended, and banned operators', function (UserStatus $status): void {
    $user = User::factory()->create([
        'platform_role' => PlatformRole::Operator,
        'status' => $status,
    ]);

    expect($user->hasActivePlatformCapability())->toBeFalse()
        ->and($user->isPlatformOperator())->toBeFalse()
        ->and($user->isPlatformAdmin())->toBeFalse();
})->with([
    UserStatus::Pending,
    UserStatus::Suspended,
    UserStatus::Banned,
]);

it('rejects soft-deleted operators', function (): void {
    $user = User::factory()->create([
        'platform_role' => PlatformRole::Admin,
        'status' => UserStatus::Active,
    ]);

    $user->delete();

    expect($user->trashed())->toBeTrue()
        ->and($user->hasActivePlatformCapability())->toBeFalse()
        ->and($user->isPlatformOperator())->toBeFalse()
        ->and($user->isPlatformAdmin())->toBeFalse();
});

it('casts platform_role to the PlatformRole enum', function (): void {
    $user = User::factory()->create(['platform_role' => 'operator']);

    expect($user->platform_role)->toBeInstanceOf(PlatformRole::class)
        ->and($user->platform_role)->toBe(PlatformRole::Operator)
        ->and(PlatformRole::cases())->toHaveCount(3);
});

it('fails closed for unknown platform roles', function (): void {
    $user = User::factory()->create([
        'status' => UserStatus::Active,
        'platform_role' => PlatformRole::Operator,
    ]);

    $user->setRawAttributes(array_merge($user->getAttributes(), [
        'platform_role' => 'not-a-role',
    ]), true);

    expect($user->hasActivePlatformCapability())->toBeFalse()
        ->and($user->isPlatformOperator())->toBeFalse()
        ->and($user->isPlatformAdmin())->toBeFalse()
        ->and(PlatformRole::tryFrom('not-a-role'))->toBeNull();
});

it('can roll back and re-run the platform role migration', function (): void {
    expect(Schema::hasColumn('users', 'platform_role'))->toBeTrue();

    $migrationPath = database_path('migrations/0001_01_01_000025_add_platform_role_to_users_table.php');

    $this->artisan('migrate:rollback', ['--path' => $migrationPath, '--realpath' => true])->assertSuccessful();
    expect(Schema::hasColumn('users', 'platform_role'))->toBeFalse();

    $this->artisan('migrate', ['--path' => $migrationPath, '--realpath' => true])->assertSuccessful();
    expect(Schema::hasColumn('users', 'platform_role'))->toBeTrue();

    $user = User::factory()->create();
    expect($user->platform_role)->toBe(PlatformRole::User);
});
