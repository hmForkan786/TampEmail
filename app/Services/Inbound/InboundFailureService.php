<?php
declare(strict_types=1);
namespace App\Services\Inbound;
use App\Enums\ProcessingLogStatus;
use App\Enums\ProcessingStage;
use App\Models\EmailProcessingLog;
final class InboundFailureService
{
    public function record(string $emailId, ProcessingStage $stage, string $code, int $attempts, array $metadata = []): EmailProcessingLog
    {
        $safe = array_intersect_key($metadata, array_flip(['scan_status','error_code','source','attachment_id']));
        $existing = EmailProcessingLog::query()->where('email_id',$emailId)->where('stage',$stage)->where('status',ProcessingLogStatus::Failed)->where('metadata->failure_code',$code)->first();
        return $existing ?? EmailProcessingLog::query()->create(['email_id'=>$emailId,'stage'=>$stage,'status'=>ProcessingLogStatus::Failed,'worker'=>'inbound-failure','duration_ms'=>0,'metadata'=>array_merge($safe,['failure_code'=>$code,'attempts'=>$attempts,'failed_at'=>now()->toIso8601String()])]);
    }
}
