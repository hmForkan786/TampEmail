<?php

use App\Filament\Admin\Resources\MailServers\MailServerResource;
use App\Models\MailServer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function filamentMailServer(array $overrides = []): MailServer
{
    return MailServer::query()->create(array_merge([
        'name' => 'Test Mail Server', 'hostname' => 'mail.test', 'provider' => 'postfix',
        'protocol' => 'smtp', 'is_active' => true, 'priority' => 1, 'max_connections' => 10,
        'timeout_seconds' => 30, 'metadata' => [],
    ], $overrides));
}

it('allows active operators and admins to list and view mail servers', function (): void {
    $server = filamentMailServer([
        'name' => 'Primary MX', 'hostname' => 'mx.example.test', 'pool_key' => 'standard',
        'max_inboxes' => 10, 'last_health_check_at' => now(),
    ]);

    foreach ([User::factory()->platformOperator()->create(), User::factory()->platformAdmin()->create()] as $actor) {
        $this->actingAs($actor)->get(MailServerResource::getUrl('index'))
            ->assertOk()->assertSee('Primary MX')->assertSee('mx.example.test')->assertSee('standard')->assertSee('Healthy');
        $this->actingAs($actor)->get(MailServerResource::getUrl('view', ['record' => $server]))
            ->assertOk()->assertSee('Primary MX')->assertSee('standard');
    }
});

it('denies non-active or non-privileged users, including direct URLs', function (): void {
    $server = filamentMailServer();
    $actors = [
        User::factory()->create(),
        User::factory()->platformOperator()->suspended()->create(),
        User::factory()->platformAdmin()->banned()->create(),
    ];

    foreach ($actors as $actor) {
        $this->actingAs($actor)->get(MailServerResource::getUrl('index'))->assertForbidden();
        $this->actingAs($actor)->get(MailServerResource::getUrl('view', ['record' => $server]))->assertForbidden();
    }
});

it('registers controlled create and edit routes without delete', function (): void {
    $names = collect(app('router')->getRoutes()->getRoutes())->map(fn ($route) => $route->getName())->filter()->all();
    expect($names)->toContain('filament.admin.resources.mail-servers.index', 'filament.admin.resources.mail-servers.view', 'filament.admin.resources.mail-servers.create', 'filament.admin.resources.mail-servers.edit')
        ->not->toContain('filament.admin.resources.mail-servers.delete');
});

it('does not render credential or raw metadata fields', function (): void {
    $admin = User::factory()->platformAdmin()->create();
    $server = filamentMailServer(['metadata' => ['password' => 'credential-secret-value', 'api_token' => 'token-secret-value', 'port' => 2525]]);

    $this->actingAs($admin)->get(MailServerResource::getUrl('view', ['record' => $server]))
        ->assertOk()->assertSee('2525')->assertDontSee('credential-secret-value')->assertDontSee('token-secret-value')->assertDontSee('api_token');
});
