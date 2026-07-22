<?php

use App\Enums\AttachmentScanStatus;
use App\Filament\Admin\Resources\InboundHolds\InboundHoldResource;
use App\Filament\Admin\Resources\InboundHolds\Pages\CreateInboundHold;
use App\Filament\Admin\Resources\InboundHolds\Pages\ListInboundHolds;
use App\Filament\Admin\Resources\InboundHolds\Pages\ViewInboundHold;
use App\Models\Attachment;
use App\Models\Domain;
use App\Models\Email;
use App\Models\EmailBody;
use App\Models\InboundHold;
use App\Models\Inbox;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

/**
 * @return array{email: Email, attachment: Attachment, inbox: Inbox}
 */
function inboundHoldFixtures(array $emailOverrides = [], array $attachmentOverrides = []): array
{
    $domain = Domain::query()->create([
        'domain' => 'ui-hold-'.bin2hex(random_bytes(3)).'.test',
        'display_name' => 'UI Hold',
        'is_active' => true,
        'is_public' => true,
        'allow_registration' => true,
        'is_healthy' => true,
        'retention_hours' => 24,
    ]);
    $inbox = Inbox::query()->create([
        'domain_id' => $domain->id,
        'local_part' => 'hold',
        'full_address' => 'hold@'.$domain->domain,
        'inbox_type' => 'temporary',
        'is_active' => true,
    ]);
    $email = Email::query()->create(array_merge([
        'inbox_id' => $inbox->id,
        'message_id' => 'ui-hold-'.bin2hex(random_bytes(4)),
        'sender_email' => 'sender@example.test',
        'recipient_email' => $inbox->full_address,
        'subject' => 'SENSITIVE-SUBJECT-SHOULD-NOT-RENDER',
        'received_at' => now(),
        'size_bytes' => 12,
        'processing_status' => 'received',
        'headers' => ['X-Secret' => 'header-secret-value'],
        'metadata' => ['token' => 'metadata-token-secret', 'authorization' => 'Bearer meta-secret'],
    ], $emailOverrides));
    EmailBody::query()->create([
        'email_id' => $email->id,
        'html_body' => '<p>SENSITIVE-HTML-BODY-CONTENT</p>',
        'text_body' => 'SENSITIVE-TEXT-BODY-CONTENT',
        'body_hash' => hash('sha256', 'body'),
    ]);
    $attachment = Attachment::query()->create(array_merge([
        'email_id' => $email->id,
        'original_filename' => 'secret-file.bin',
        'stored_filename' => 'opaque-stored',
        'mime_type' => 'application/octet-stream',
        'extension' => 'bin',
        'size_bytes' => 4,
        'checksum_sha256' => hash('sha256', 'SENSITIVE-ATTACHMENT-BYTES'),
        'storage_disk' => 'attachments',
        'storage_path' => 'quarantine/'.$email->id.'/opaque-stored',
        'scan_status' => AttachmentScanStatus::Pending,
        'is_safe' => null,
        'metadata' => ['api_key' => 'attachment-api-key-secret'],
    ], $attachmentOverrides));

    return compact('email', 'attachment', 'inbox');
}

function uiInboundHold(array $overrides = []): InboundHold
{
    $admin = User::factory()->platformAdmin()->create([
        'email' => 'hold-actor-'.bin2hex(random_bytes(2)).'@example.test',
    ]);
    $fixtures = inboundHoldFixtures();

    return InboundHold::query()->create(array_merge([
        'target_type' => 'email',
        'target_id' => $fixtures['email']->id,
        'held_by_user_id' => $admin->id,
        'reason' => 'security review hold',
    ], $overrides));
}

it('allows active admins to list view create and release inbound holds', function (): void {
    $admin = User::factory()->platformAdmin()->create(['email' => 'admin-hold@example.test']);
    $fixtures = inboundHoldFixtures();
    $hold = InboundHold::query()->create([
        'target_type' => 'email',
        'target_id' => $fixtures['email']->id,
        'held_by_user_id' => $admin->id,
        'reason' => 'incident hold reason',
    ]);

    $this->actingAs($admin)
        ->get(InboundHoldResource::getUrl('index'))
        ->assertOk()
        ->assertSee('incident hold reason')
        ->assertSee('Indefinite');

    $this->actingAs($admin)
        ->get(InboundHoldResource::getUrl('view', ['record' => $hold]))
        ->assertOk()
        ->assertSee('incident hold reason')
        ->assertSee((string) $fixtures['email']->id)
        ->assertSee('Yes')
        ->assertSee('admin-hold@example.test');

    Livewire::actingAs($admin)
        ->test(ViewInboundHold::class, ['record' => $hold->getKey()])
        ->assertSuccessful()
        ->assertActionExists('release')
        ->callAction('release')
        ->assertNotified();

    expect($hold->fresh()->released_at)->not->toBeNull()
        ->and($hold->fresh()->released_by_user_id)->toBe((string) $admin->id);

    $createFixtures = inboundHoldFixtures();
    Livewire::actingAs($admin)
        ->test(CreateInboundHold::class)
        ->fillForm([
            'target_type' => 'inbox',
            'target_id' => $createFixtures['inbox']->id,
            'reason' => 'created via filament',
            'held_until' => null,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(InboundHold::query()->where('target_type', 'inbox')->where('reason', 'created via filament')->exists())->toBeTrue();
});

it('denies operators ordinary users and inactive admins including direct urls', function (): void {
    $hold = uiInboundHold();
    $actors = [
        User::factory()->platformOperator()->create(),
        User::factory()->create(),
        User::factory()->platformAdmin()->pending()->create(),
        User::factory()->platformAdmin()->suspended()->create(),
        User::factory()->platformAdmin()->banned()->create(),
    ];

    foreach ($actors as $actor) {
        $this->actingAs($actor);
        expect(InboundHoldResource::canViewAny())->toBeFalse()
            ->and(InboundHoldResource::shouldRegisterNavigation())->toBeFalse();
        $this->get(InboundHoldResource::getUrl('index'))->assertForbidden();
        $this->get(InboundHoldResource::getUrl('view', ['record' => $hold]))->assertForbidden();
        $this->get(InboundHoldResource::getUrl('create'))->assertForbidden();
    }

    $deleted = User::factory()->platformAdmin()->create();
    $deleted->delete();
    $this->actingAs($deleted)->get(InboundHoldResource::getUrl('index'))->assertForbidden();
});

it('registers index create and view routes without edit delete or export', function (): void {
    $names = collect(app('router')->getRoutes()->getRoutes())
        ->map(fn ($route) => $route->getName())
        ->filter()
        ->all();

    expect($names)
        ->toContain(
            'filament.admin.resources.inbound-holds.index',
            'filament.admin.resources.inbound-holds.create',
            'filament.admin.resources.inbound-holds.view',
        )
        ->not->toContain(
            'filament.admin.resources.inbound-holds.edit',
            'filament.admin.resources.inbound-holds.delete',
            'filament.admin.resources.inbound-holds.export',
        );

    $admin = User::factory()->platformAdmin()->create();
    $hold = uiInboundHold();

    $this->actingAs($admin)
        ->get('/admin/inbound-holds/'.$hold->id.'/edit')
        ->assertNotFound();

    Livewire::actingAs($admin)
        ->test(ListInboundHolds::class)
        ->assertSuccessful()
        ->assertTableActionDoesNotExist('edit')
        ->assertTableActionDoesNotExist('delete')
        ->assertTableBulkActionDoesNotExist('delete')
        ->assertTableBulkActionDoesNotExist('export');

    Livewire::actingAs($admin)
        ->test(ViewInboundHold::class, ['record' => $hold->getKey()])
        ->assertSuccessful()
        ->assertActionDoesNotExist('edit')
        ->assertActionDoesNotExist('delete');
});

it('displays active expired released and indefinite statuses and hides release when not active', function (): void {
    $admin = User::factory()->platformAdmin()->create();
    $fixtures = inboundHoldFixtures();

    $indefinite = InboundHold::query()->create([
        'target_type' => 'email',
        'target_id' => $fixtures['email']->id,
        'held_by_user_id' => $admin->id,
        'reason' => 'indefinite hold reason',
        'held_until' => null,
    ]);
    $active = InboundHold::query()->create([
        'target_type' => 'attachment',
        'target_id' => $fixtures['attachment']->id,
        'held_by_user_id' => $admin->id,
        'reason' => 'timed active hold reason',
        'held_until' => now()->addDay(),
    ]);
    $expired = InboundHold::query()->create([
        'target_type' => 'inbox',
        'target_id' => $fixtures['inbox']->id,
        'held_by_user_id' => $admin->id,
        'reason' => 'expired hold reason',
        'held_until' => now()->subHour(),
    ]);
    $released = InboundHold::query()->create([
        'target_type' => 'email',
        'target_id' => inboundHoldFixtures()['email']->id,
        'held_by_user_id' => $admin->id,
        'reason' => 'released hold reason',
        'held_until' => now()->addDays(2),
        'released_at' => now(),
        'released_by_user_id' => $admin->id,
    ]);

    expect(InboundHoldResource::status($indefinite))->toBe('Indefinite')
        ->and(InboundHoldResource::status($active))->toBe('Active')
        ->and(InboundHoldResource::status($expired))->toBe('Expired')
        ->and(InboundHoldResource::status($released))->toBe('Released');

    $this->actingAs($admin)->get(InboundHoldResource::getUrl('index'))
        ->assertOk()
        ->assertSee('Indefinite')
        ->assertSee('Active')
        ->assertSee('Expired')
        ->assertSee('Released');

    Livewire::actingAs($admin)
        ->test(ViewInboundHold::class, ['record' => $indefinite->getKey()])
        ->assertActionExists('release');

    Livewire::actingAs($admin)
        ->test(ViewInboundHold::class, ['record' => $active->getKey()])
        ->assertActionExists('release');

    Livewire::actingAs($admin)
        ->test(ViewInboundHold::class, ['record' => $expired->getKey()])
        ->assertActionHidden('release');

    Livewire::actingAs($admin)
        ->test(ViewInboundHold::class, ['record' => $released->getKey()])
        ->assertActionHidden('release');
});

it('surfaces duplicate active hold as a create form validation error', function (): void {
    $admin = User::factory()->platformAdmin()->create();
    $fixtures = inboundHoldFixtures();
    InboundHold::query()->create([
        'target_type' => 'email',
        'target_id' => $fixtures['email']->id,
        'held_by_user_id' => $admin->id,
        'reason' => 'existing active',
    ]);

    Livewire::actingAs($admin)
        ->test(CreateInboundHold::class)
        ->fillForm([
            'target_type' => 'email',
            'target_id' => $fixtures['email']->id,
            'reason' => 'duplicate attempt',
        ])
        ->call('create')
        ->assertHasFormErrors(['target_id' => 'An active inbound hold already exists.']);

    expect(InboundHold::query()->where('target_id', $fixtures['email']->id)->count())->toBe(1);
});

it('shows parent email hold and child attachment protection indicators', function (): void {
    $admin = User::factory()->platformAdmin()->create();
    $fixtures = inboundHoldFixtures();

    $emailHold = InboundHold::query()->create([
        'target_type' => 'email',
        'target_id' => $fixtures['email']->id,
        'held_by_user_id' => $admin->id,
        'reason' => 'parent email hold reason',
    ]);
    $attachmentHold = InboundHold::query()->create([
        'target_type' => 'attachment',
        'target_id' => $fixtures['attachment']->id,
        'held_by_user_id' => $admin->id,
        'reason' => 'child attachment hold reason',
    ]);

    expect(InboundHoldResource::parentEmailHoldIndicator($emailHold))->toBe('N/A — email target')
        ->and(InboundHoldResource::childAttachmentProtectionIndicator($emailHold))->toBe('Yes')
        ->and(InboundHoldResource::parentEmailHoldIndicator($attachmentHold))->toBe('Yes')
        ->and(InboundHoldResource::childAttachmentProtectionIndicator($attachmentHold))->toContain('parent email hold');

    $this->actingAs($admin)
        ->get(InboundHoldResource::getUrl('view', ['record' => $emailHold]))
        ->assertOk()
        ->assertSee('Parent email hold')
        ->assertSee('Child attachment protection')
        ->assertSee('N/A — email target');

    $this->actingAs($admin)
        ->get(InboundHoldResource::getUrl('view', ['record' => $attachmentHold]))
        ->assertOk()
        ->assertSee('Parent email hold')
        ->assertSee('Yes');
});

it('never renders sensitive email body mime headers credentials tokens or metadata', function (): void {
    $admin = User::factory()->platformAdmin()->create();
    $fixtures = inboundHoldFixtures();
    $hold = InboundHold::query()->create([
        'target_type' => 'attachment',
        'target_id' => $fixtures['attachment']->id,
        'held_by_user_id' => $admin->id,
        'reason' => 'safe operational reason',
    ]);

    $list = $this->actingAs($admin)->get(InboundHoldResource::getUrl('index'));
    $view = $this->actingAs($admin)->get(InboundHoldResource::getUrl('view', ['record' => $hold]));

    foreach ([$list, $view] as $response) {
        $response->assertOk()
            ->assertSee('safe operational reason')
            ->assertSee((string) $fixtures['attachment']->id)
            ->assertDontSee('SENSITIVE-SUBJECT-SHOULD-NOT-RENDER')
            ->assertDontSee('SENSITIVE-HTML-BODY-CONTENT')
            ->assertDontSee('SENSITIVE-TEXT-BODY-CONTENT')
            ->assertDontSee('header-secret-value')
            ->assertDontSee('metadata-token-secret')
            ->assertDontSee('Bearer meta-secret')
            ->assertDontSee('secret-file.bin')
            ->assertDontSee('SENSITIVE-ATTACHMENT-BYTES')
            ->assertDontSee('attachment-api-key-secret')
            ->assertDontSee($fixtures['attachment']->checksum_sha256)
            ->assertDontSee($fixtures['attachment']->storage_path);
    }
});

it('supports search and filters for target type id status actor and expiry', function (): void {
    $admin = User::factory()->platformAdmin()->create(['email' => 'filter-holder@example.test']);
    $other = User::factory()->platformAdmin()->create(['email' => 'other-holder@example.test']);
    $fixturesA = inboundHoldFixtures();
    $fixturesB = inboundHoldFixtures();

    $emailHold = InboundHold::query()->create([
        'target_type' => 'email',
        'target_id' => $fixturesA['email']->id,
        'held_by_user_id' => $admin->id,
        'reason' => 'filterable email hold',
        'held_until' => now()->addDays(3),
    ]);
    $attachmentHold = InboundHold::query()->create([
        'target_type' => 'attachment',
        'target_id' => $fixturesB['attachment']->id,
        'held_by_user_id' => $other->id,
        'reason' => 'filterable attachment hold',
        'held_until' => now()->addDays(10),
    ]);

    Livewire::actingAs($admin)
        ->test(ListInboundHolds::class)
        ->assertCanSeeTableRecords([$emailHold, $attachmentHold])
        ->set('tableSearch', (string) $fixturesA['email']->id)
        ->assertCanSeeTableRecords([$emailHold])
        ->assertCanNotSeeTableRecords([$attachmentHold])
        ->set('tableSearch', '')
        ->filterTable('target_type', 'attachment')
        ->assertCanSeeTableRecords([$attachmentHold])
        ->assertCanNotSeeTableRecords([$emailHold])
        ->resetTableFilters()
        ->filterTable('target_id', ['value' => (string) $fixturesA['email']->id])
        ->assertCanSeeTableRecords([$emailHold])
        ->assertCanNotSeeTableRecords([$attachmentHold])
        ->resetTableFilters()
        ->filterTable('status', 'active')
        ->assertCanSeeTableRecords([$emailHold, $attachmentHold])
        ->resetTableFilters()
        ->filterTable('held_by_user_id', (string) $other->getKey())
        ->assertCanSeeTableRecords([$attachmentHold])
        ->assertCanNotSeeTableRecords([$emailHold])
        ->resetTableFilters()
        ->filterTable('held_until', [
            'from' => now()->addDays(8)->toDateString(),
            'until' => now()->addDays(12)->toDateString(),
        ])
        ->assertCanSeeTableRecords([$attachmentHold])
        ->assertCanNotSeeTableRecords([$emailHold]);
});
