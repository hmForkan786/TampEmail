<?php
use App\Filament\Admin\Resources\ApiRequestLogs\ApiRequestLogResource;
use App\Models\ApiKey;
use App\Models\ApiRequestLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
uses(RefreshDatabase::class);

function filamentRequestLog(array $overrides = []): ApiRequestLog
{
    $owner = User::factory()->create(['email' => 'request-owner@example.test']);
    $key = ApiKey::query()->create(['user_id'=>$owner->id,'name'=>'log-key','key_prefix'=>'te_test','key_hash'=>hash('sha256', uniqid()),'permissions'=>['mail_servers:read'],'rate_limit_per_minute'=>60]);
    return ApiRequestLog::query()->create(array_merge(['api_key_id'=>$key->id,'user_id'=>$owner->id,'method'=>'GET','endpoint'=>'api.v1.mail-servers.index','ip_address'=>'203.0.113.10','response_status'=>200,'response_time_ms'=>25,'request_size_bytes'=>12,'response_size_bytes'=>300,'metadata'=>['was_throttled'=>false]], $overrides));
}

it('allows only active admins to list and view request logs', function (): void {
    $admin = User::factory()->platformAdmin()->create(); $log = filamentRequestLog(['response_status'=>429,'metadata'=>['was_throttled'=>true]]);
    $this->actingAs($admin)->get(ApiRequestLogResource::getUrl('index'))->assertOk()->assertSee('api.v1.mail-servers.index')->assertSee('429');
    $this->actingAs($admin)->get(ApiRequestLogResource::getUrl('view',['record'=>$log]))->assertOk()->assertSee('request-owner@example.test')->assertSee('Yes')->assertSee('203.0.113.10');
    expect(ApiRequestLogResource::canViewAny())->toBeTrue()->and(ApiRequestLogResource::canCreate())->toBeFalse()->and(ApiRequestLogResource::canEdit($log))->toBeFalse()->and(ApiRequestLogResource::canDelete($log))->toBeFalse();
});

it('denies operators and does not register mutation routes', function (): void {
    $operator = User::factory()->platformOperator()->create(); $log = filamentRequestLog();
    $this->actingAs($operator)->get(ApiRequestLogResource::getUrl('index'))->assertForbidden();
    $this->actingAs($operator)->get(ApiRequestLogResource::getUrl('view',['record'=>$log]))->assertForbidden();
    $names = collect(app('router')->getRoutes()->getRoutes())->map(fn ($r) => $r->getName())->filter()->all();
    expect($names)->toContain('filament.admin.resources.api-request-logs.index','filament.admin.resources.api-request-logs.view')->not->toContain('filament.admin.resources.api-request-logs.create','filament.admin.resources.api-request-logs.edit');
});

it('never renders sensitive log payload values', function (): void {
    $admin = User::factory()->platformAdmin()->create(); $log = filamentRequestLog(['metadata'=>['was_throttled'=>true,'authorization'=>'Bearer secret-token','body'=>'password-body','key_hash'=>'hash-secret']]);
    $this->actingAs($admin)->get(ApiRequestLogResource::getUrl('view',['record'=>$log]))->assertOk()->assertSee('was_throttled: true')->assertDontSee('secret-token')->assertDontSee('password-body')->assertDontSee('hash-secret')->assertDontSee('authorization');
});
