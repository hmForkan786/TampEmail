<?php
use App\Actions\Inbound\CreateInboundHoldAction;
use App\Actions\Inbound\ReleaseInboundHoldAction;
use App\DTOs\Inbound\CreateInboundHoldData;
use App\Models\Domain;
use App\Models\Email;
use App\Models\InboundHold;
use App\Models\Inbox;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
uses(RefreshDatabase::class);

function inboundHoldTarget(): Email
{
    $domain = Domain::query()->create(['domain'=>'hold-'.bin2hex(random_bytes(3)).'.test','display_name'=>'Hold','is_active'=>true,'is_public'=>true,'allow_registration'=>true,'is_healthy'=>true,'retention_hours'=>24]);
    $inbox = Inbox::query()->create(['domain_id'=>$domain->id,'local_part'=>'inbox','full_address'=>'inbox@'.$domain->domain,'inbox_type'=>'temporary','is_active'=>true]);
    return Email::query()->create(['inbox_id'=>$inbox->id,'message_id'=>'hold-'.bin2hex(random_bytes(3)),'sender_email'=>'a@test','recipient_email'=>$inbox->full_address,'received_at'=>now(),'size_bytes'=>1,'processing_status'=>'received']);
}

it('creates and releases an indefinite inbound email hold', function (): void {
    $admin = User::factory()->platformAdmin()->create(); $email = inboundHoldTarget();
    $hold = app(CreateInboundHoldAction::class)->execute(new CreateInboundHoldData('email',(string)$email->id,(string)$admin->id,'incident review'));
    expect($hold->isActive())->toBeTrue()->and(InboundHold::query()->count())->toBe(1);
    $released = app(ReleaseInboundHoldAction::class)->execute((string)$hold->id,(string)$admin->id);
    expect($released->isActive())->toBeFalse()->and($released->released_by_user_id)->toBe((string)$admin->id);
    app(ReleaseInboundHoldAction::class)->execute((string)$hold->id,(string)$admin->id);
    expect(InboundHold::query()->count())->toBe(1);
});

it('rejects duplicate active holds and non-admin actors', function (): void {
    $admin = User::factory()->platformAdmin()->create(); $operator = User::factory()->platformOperator()->create(); $email = inboundHoldTarget();
    $data = new CreateInboundHoldData('email',(string)$email->id,(string)$admin->id,'review');
    app(CreateInboundHoldAction::class)->execute($data);
    expect(fn () => app(CreateInboundHoldAction::class)->execute($data))->toThrow(InvalidArgumentException::class);
    expect(fn () => app(CreateInboundHoldAction::class)->execute(new CreateInboundHoldData('email',(string)$email->id,(string)$operator->id,'review')))->toThrow(\Illuminate\Auth\Access\AuthorizationException::class);
});
