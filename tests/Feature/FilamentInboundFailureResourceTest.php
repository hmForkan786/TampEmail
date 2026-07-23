<?php

use App\Enums\ProcessingLogStatus;
use App\Enums\ProcessingStage;
use App\Filament\Admin\Resources\InboundFailures\InboundFailureResource;
use App\Filament\Admin\Resources\InboundFailures\Pages\ListInboundFailures;
use App\Filament\Admin\Resources\InboundFailures\Pages\ViewInboundFailure;
use App\Models\Domain;
use App\Models\Email;
use App\Models\EmailProcessingLog;
use App\Models\Inbox;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

function inboundFailureFixture(): array
{
    $domain = Domain::query()->create(['domain' => 'failure-'.bin2hex(random_bytes(3)).'.test', 'display_name' => 'Failures', 'is_active' => true, 'is_public' => true, 'allow_registration' => true, 'is_healthy' => true, 'retention_hours' => 24]);
    $inbox = Inbox::query()->create(['domain_id' => $domain->id, 'local_part' => 'dlq', 'full_address' => 'dlq@'.$domain->domain, 'inbox_type' => 'temporary', 'is_active' => true]);
    $email = Email::query()->create(['inbox_id' => $inbox->id, 'message_id' => 'failure-'.bin2hex(random_bytes(3)), 'sender_email' => 'sender@test', 'recipient_email' => $inbox->full_address, 'received_at' => now(), 'size_bytes' => 1, 'processing_status' => 'received']);
    return ['email' => $email, 'failure' => EmailProcessingLog::query()->create(['email_id' => $email->id, 'stage' => ProcessingStage::Scan, 'status' => ProcessingLogStatus::Failed, 'worker' => 'scanner', 'error_message' => 'scanner failed: token=secret and command=clamscan', 'metadata' => ['failure_code' => 'attachment_scan_retry_exhausted', 'attempts' => 3, 'attachment_id' => bin2hex(random_bytes(16)), 'failed_at' => now()->toIso8601String(), 'retryable' => true, 'raw_mime' => 'MIME secret']])];
}

it('allows admins to list and view safe failure fields only', function (): void {
    $admin = User::factory()->platformAdmin()->create();
    $fixture = inboundFailureFixture();
    $this->actingAs($admin)->get(InboundFailureResource::getUrl('index'))->assertOk()->assertSee('attachment_scan_retry_exhausted')->assertSee($fixture['email']->message_id)->assertDontSee('MIME secret')->assertDontSee('clamscan')->assertDontSee('token=secret');
    $this->actingAs($admin)->get(InboundFailureResource::getUrl('view', ['record' => $fixture['failure']]))->assertOk()->assertSee('attachment_scan_retry_exhausted')->assertDontSee('MIME secret')->assertDontSee('clamscan');
});

it('denies operators ordinary users inactive and deleted admins', function (): void {
    $fixture = inboundFailureFixture();
    foreach ([User::factory()->platformOperator()->create(), User::factory()->create(), User::factory()->platformAdmin()->create(['status' => 'suspended'])] as $actor) {
        $this->actingAs($actor);
        expect(InboundFailureResource::canViewAny())->toBeFalse()->and(InboundFailureResource::shouldRegisterNavigation())->toBeFalse();
        $this->get(InboundFailureResource::getUrl('index'))->assertForbidden();
        $this->get(InboundFailureResource::getUrl('view', ['record' => $fixture['failure']]))->assertForbidden();
    }
});

it('is read-only and has no mutation or export routes', function (): void {
    $admin = User::factory()->platformAdmin()->create();
    $failure = inboundFailureFixture()['failure'];
    $routes = collect(app('router')->getRoutes()->getRoutes())->map(fn ($route) => $route->getName())->filter()->all();
    expect($routes)->not->toContain('filament.admin.resources.inbound-failures.create')->and($routes)->not->toContain('filament.admin.resources.inbound-failures.edit');
    Livewire::actingAs($admin)->test(ListInboundFailures::class)->assertSuccessful()->assertTableActionExists('view')->assertTableActionDoesNotExist('edit')->assertTableActionDoesNotExist('delete')->assertTableBulkActionDoesNotExist('delete');
    Livewire::actingAs($admin)->test(ViewInboundFailure::class, ['record' => $failure->id])->assertSuccessful()->assertActionDoesNotExist('edit')->assertActionDoesNotExist('delete')->assertActionDoesNotExist('replay');
});

it('searches and paginates failures without rendering raw metadata', function (): void {
    $admin = User::factory()->platformAdmin()->create();
    $first = inboundFailureFixture()['failure'];
    $second = inboundFailureFixture()['failure'];
    Livewire::actingAs($admin)->test(ListInboundFailures::class)->searchTable('attachment_scan_retry_exhausted')->assertCanSeeTableRecords([$first, $second]);
});
