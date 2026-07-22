<?php

use App\Actions\User\ChangePlatformRoleAction;
use App\Actions\User\ChangeUserStatusAction;
use App\DTOs\User\ChangePlatformRoleData;
use App\DTOs\User\ChangeUserStatusData;
use App\Enums\BillingCycle;
use App\Enums\PlatformRole;
use App\Enums\SubscriptionStatus;
use App\Enums\UserStatus;
use App\Enums\ValueType;
use App\Models\Feature;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function adminPanel(): Panel
{
    return Filament::getPanel('admin');
}

function attachMailServerPoolsEntitlement(User $user): void
{
    $plan = Plan::create([
        'slug' => 'filament-pool-'.uniqid(),
        'name' => 'Filament Pool Plan',
        'price_monthly' => '0.00',
        'price_yearly' => '0.00',
        'currency' => 'USD',
        'is_free' => true,
        'is_active' => true,
        'display_order' => 1,
    ]);

    $poolsFeature = Feature::query()->firstOrCreate(
        ['key' => 'mail_server_pools'],
        [
            'name' => 'Mail Server Pools',
            'value_type' => ValueType::Json,
            'is_active' => true,
            'display_order' => 2,
        ],
    );

    $plan->features()->attach($poolsFeature->id, [
        'feature_value' => ['pools' => ['standard']],
    ]);

    Subscription::create([
        'user_id' => $user->id,
        'plan_id' => $plan->id,
        'status' => SubscriptionStatus::Active,
        'billing_cycle' => BillingCycle::Monthly,
        'starts_at' => now()->subDay(),
        'auto_renew' => true,
        'price' => '0.00',
        'currency' => 'USD',
    ]);
}

it('denies panel access to an active ordinary user', function (): void {
    $user = User::factory()->create();

    expect($user->canAccessPanel(adminPanel()))->toBeFalse();

    $this->actingAs($user)
        ->get('/admin')
        ->assertForbidden();
});

it('allows panel access to an active operator', function (): void {
    $user = User::factory()->platformOperator()->create();

    expect($user->canAccessPanel(adminPanel()))->toBeTrue();

    $this->actingAs($user)
        ->get('/admin')
        ->assertOk();
});

it('allows panel access to an active admin', function (): void {
    $user = User::factory()->platformAdmin()->create();

    expect($user->canAccessPanel(adminPanel()))->toBeTrue();

    $this->actingAs($user)
        ->get('/admin')
        ->assertOk();
});

it('denies pending, suspended, and banned operators', function (UserStatus $status): void {
    $user = User::factory()->platformOperator()->create(['status' => $status]);

    expect($user->canAccessPanel(adminPanel()))->toBeFalse();

    $this->actingAs($user)
        ->get('/admin')
        ->assertForbidden();
})->with([
    UserStatus::Pending,
    UserStatus::Suspended,
    UserStatus::Banned,
]);

it('denies pending, suspended, and banned admins', function (UserStatus $status): void {
    $user = User::factory()->platformAdmin()->create(['status' => $status]);

    expect($user->canAccessPanel(adminPanel()))->toBeFalse();

    $this->actingAs($user)
        ->get('/admin')
        ->assertForbidden();
})->with([
    UserStatus::Pending,
    UserStatus::Suspended,
    UserStatus::Banned,
]);

it('fails closed for unknown platform roles', function (): void {
    $user = User::factory()->platformOperator()->create();

    $user->setRawAttributes(array_merge($user->getAttributes(), [
        'platform_role' => 'not-a-role',
    ]), true);

    expect($user->canAccessPanel(adminPanel()))->toBeFalse();
});

it('denies ordinary users who hold mail_server_pools entitlement', function (): void {
    $user = User::factory()->create();
    attachMailServerPoolsEntitlement($user);

    expect($user->canAccessPanel(adminPanel()))->toBeFalse();

    $this->actingAs($user)
        ->get('/admin')
        ->assertForbidden();
});

it('denies panel access after operator demotion to user', function (): void {
    $admin = User::factory()->platformAdmin()->create();
    $operator = User::factory()->platformOperator()->create();

    expect($operator->canAccessPanel(adminPanel()))->toBeTrue();

    app(ChangePlatformRoleAction::class)->execute(new ChangePlatformRoleData(
        actorUserId: (string) $admin->id,
        targetUserId: (string) $operator->id,
        newRole: PlatformRole::User,
    ));

    $operator = $operator->fresh();

    expect($operator->canAccessPanel(adminPanel()))->toBeFalse();

    $this->actingAs($operator)
        ->get('/admin')
        ->assertForbidden();
});

it('denies panel access after suspending an active admin', function (): void {
    $actor = User::factory()->platformAdmin()->create();
    $target = User::factory()->platformAdmin()->create();

    expect($target->canAccessPanel(adminPanel()))->toBeTrue();

    app(ChangeUserStatusAction::class)->execute(new ChangeUserStatusData(
        actorUserId: (string) $actor->id,
        targetUserId: (string) $target->id,
        newStatus: UserStatus::Suspended,
    ));

    $target = $target->fresh();

    expect($target->canAccessPanel(adminPanel()))->toBeFalse();

    $this->actingAs($target)
        ->get('/admin')
        ->assertForbidden();
});

it('denies soft-deleted privileged users', function (): void {
    $user = User::factory()->platformAdmin()->create();
    $user->delete();

    expect($user->trashed())->toBeTrue()
        ->and($user->canAccessPanel(adminPanel()))->toBeFalse();
});

it('does not break ordinary web authentication for non-staff users', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/')
        ->assertOk();

    expect(auth()->check())->toBeTrue()
        ->and(auth()->id())->toBe($user->id)
        ->and($user->canAccessPanel(adminPanel()))->toBeFalse();
});
