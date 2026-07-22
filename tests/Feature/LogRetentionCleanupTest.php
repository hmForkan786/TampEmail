<?php
use App\Models\ApiRequestLog;
use App\Models\AuditLog;
use App\Models\AuditLogHold;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
uses(RefreshDatabase::class);

function oldRequestLog(): ApiRequestLog { $log = ApiRequestLog::query()->create(['method'=>'GET','endpoint'=>'test','ip_address'=>'127.0.0.1','response_status'=>200,'response_time_ms'=>1]); $log->timestamps = false; $log->created_at = now()->subDays(40); $log->save(); return $log; }

it('dry runs without deleting and confirm deletes API logs in bounded cleanup', function (): void {
    oldRequestLog(); oldRequestLog();
    $this->artisan('logs:cleanup', ['--dry-run'=>true])->expectsOutputToContain('API request logs eligible: 2')->expectsOutputToContain('API request logs deleted: 0')->assertSuccessful();
    expect(ApiRequestLog::query()->count())->toBe(2);
    $this->artisan('logs:cleanup', ['--confirm'=>true, '--batch'=>1])->expectsOutputToContain('API request logs deleted: 2')->assertSuccessful();
    expect(ApiRequestLog::query()->count())->toBe(0);
});

it('blocks audit deletion when hold support is absent', function (): void {
    $log = AuditLog::query()->create(['action'=>'test']); $log->timestamps = false; $log->created_at = now()->subDays(3000); $log->save();
    AuditLogHold::query()->create(['audit_log_id'=>$log->id,'held_by_user_id'=>\App\Models\User::factory()->platformAdmin()->create()->id,'reason'=>'investigation']);
    $this->artisan('logs:cleanup', ['--confirm'=>true, '--confirm-audit-delete'=>true])->expectsOutputToContain('Audit logs deleted: 0')->expectsOutputToContain('Skipped due to hold: yes')->assertSuccessful();
    expect(AuditLog::query()->count())->toBe(1);
});

it('rejects invalid retention and defaults execution to dry run', function (): void {
    config(['retention.api_request_logs_days' => 1]);
    $this->artisan('logs:cleanup', ['--confirm'=>true])->assertFailed();
    config(['retention.api_request_logs_days' => 30]);
    oldRequestLog();
    $this->artisan('logs:cleanup')->expectsOutputToContain('API request logs deleted: 0')->assertSuccessful();
});
