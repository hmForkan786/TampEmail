<?php
use App\Filament\Admin\Resources\AuditLogHolds\AuditLogHoldResource;
use App\Models\AuditLog;
use App\Models\AuditLogHold;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use App\Filament\Admin\Resources\AuditLogHolds\Pages\ListAuditLogHolds;
use App\Filament\Admin\Resources\AuditLogHolds\Pages\ViewAuditLogHold;
uses(RefreshDatabase::class);
function uiHold(): AuditLogHold { $admin=User::factory()->platformAdmin()->create(); $log=AuditLog::query()->create(['action'=>'mail_server.created']); return AuditLogHold::query()->create(['audit_log_id'=>$log->id,'held_by_user_id'=>$admin->id,'reason'=>'security review']); }
it('allows active admins to list and view holds with release action', function (): void { $admin=User::factory()->platformAdmin()->create(); $hold=uiHold(); $this->actingAs($admin)->get(AuditLogHoldResource::getUrl('index'))->assertOk()->assertSee('security review'); $this->actingAs($admin)->get(AuditLogHoldResource::getUrl('view',['record'=>$hold]))->assertOk()->assertSee('security review'); Livewire::actingAs($admin)->test(ViewAuditLogHold::class,['record'=>$hold->id])->assertActionExists('release'); });
it('denies operators and keeps hold resource read-only apart from create/release', function (): void { $operator=User::factory()->platformOperator()->create(); $hold=uiHold(); $this->actingAs($operator)->get(AuditLogHoldResource::getUrl('index'))->assertForbidden(); $this->actingAs($operator)->get(AuditLogHoldResource::getUrl('view',['record'=>$hold]))->assertForbidden(); $names=collect(app('router')->getRoutes()->getRoutes())->map(fn($r)=>$r->getName())->filter()->all(); expect($names)->toContain('filament.admin.resources.audit-log-holds.index','filament.admin.resources.audit-log-holds.create','filament.admin.resources.audit-log-holds.view')->not->toContain('filament.admin.resources.audit-log-holds.edit'); Livewire::actingAs(User::factory()->platformAdmin()->create())->test(ListAuditLogHolds::class)->assertTableBulkActionDoesNotExist('delete'); });
it('hides release action for released holds', function (): void { $admin=User::factory()->platformAdmin()->create(); $hold=uiHold(); $hold->update(['released_at'=>now(),'released_by_user_id'=>$admin->id]); Livewire::actingAs($admin)->test(ViewAuditLogHold::class,['record'=>$hold->id])->assertActionHidden('release'); });
