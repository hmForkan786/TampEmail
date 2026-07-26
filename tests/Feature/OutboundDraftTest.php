<?php

declare(strict_types=1);

use App\Enums\OutboundMessageState;
use App\Jobs\DeliverOutboundMessageJob;
use App\Models\OutboundMessage;
use App\Models\User;
use App\Services\Outbound\OutboundDraftService;
use App\Services\Outbound\OutboundPruneService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'api.key_hash_secret' => 'outbound-draft-test-secret',
        'outbound.enabled' => true,
        'outbound.send_enabled' => true,
        'outbound.rollout.mode' => 'enabled',
        'outbound.rollout.emergency_stop' => false,
        'queue.default' => 'sync',
    ]);
});

it('creates updates and submits an owned send draft exactly once', function (): void {
    Queue::fake();
    $ctx = outboundSendContext();
    $created = $this->withToken($ctx['token'])->postJson('/api/v1/outbound-drafts', [
        'inbox_id' => $ctx['inbox']->id, 'operation' => 'send', 'html_body' => '<p>Safe<script>alert(1)</script></p>',
    ])->assertCreated()->assertJsonPath('data.state', 'draft')->assertJsonPath('data.draft_version', 1);
    expect($created->json('data.html_body'))->not->toContain('<script>');
    $id = $created->json('data.id');
    $this->withToken($ctx['token'])->patchJson('/api/v1/outbound-drafts/'.$id, [
        'version' => 1, 'to' => ['recipient@example.test'], 'subject' => 'Draft', 'text_body' => 'Body',
    ])->assertOk()->assertJsonPath('data.draft_version', 2);
    $this->withToken($ctx['token'])->patchJson('/api/v1/outbound-drafts/'.$id, ['version' => 1, 'subject' => 'stale'])->assertStatus(409);
    $this->withToken($ctx['token'])->postJson('/api/v1/outbound-drafts/'.$id.'/submit', ['version' => 2])->assertOk()->assertJsonPath('data.state', 'queued');
    $this->withToken($ctx['token'])->postJson('/api/v1/outbound-drafts/'.$id.'/submit', ['version' => 2])->assertOk()->assertJsonPath('data.state', 'queued');
    expect(OutboundMessage::query()->findOrFail($id)->state)->toBe(OutboundMessageState::Queued);
    Queue::assertPushed(DeliverOutboundMessageJob::class, 1);
});

it('keeps partial drafts outside normal outbound usage', function (): void {
    $ctx = outboundSendContext();
    $this->withToken($ctx['token'])->postJson('/api/v1/outbound-drafts', ['inbox_id' => $ctx['inbox']->id, 'operation' => 'send'])->assertCreated();
    expect(OutboundMessage::query()->where('state', OutboundMessageState::Draft)->count())->toBe(1);
});

it('renders only owner scoped safe draft summaries on the web page', function (): void {
    $ctx = outboundSendContext();
    $draft = app(OutboundDraftService::class)->create($ctx['user'], ['inbox_id' => $ctx['inbox']->id, 'operation' => 'send', 'subject' => 'Safe summary', 'text_body' => 'PRIVATE BODY', 'to' => ['visible@example.test'], 'bcc' => ['hidden@example.test']]);
    $this->actingAs($ctx['user'])->get(route('outbound-drafts.index'))->assertOk()->assertSee('Safe summary')->assertDontSee('PRIVATE BODY')->assertDontSee('hidden@example.test');
    $other = User::factory()->create();
    $this->actingAs($other)->get(route('outbound-drafts.edit', $draft))->assertNotFound();
});

it('prunes only stale unheld drafts idempotently without dispatching jobs', function (): void {
    Queue::fake();
    config(['outbound_retention.cleanup_enabled' => true, 'outbound_retention.draft_days' => 30]);
    $ctx = outboundSendContext();
    $draft = app(OutboundDraftService::class)->create($ctx['user'], ['inbox_id' => $ctx['inbox']->id, 'operation' => 'send', 'to' => ['private@example.test'], 'subject' => 'Private', 'text_body' => 'secret', 'html_body' => '<p>secret</p>']);
    $draft->forceFill(['updated_at' => now()->subDays(31)])->save();
    $service = app(OutboundPruneService::class);
    $service->prune(false, true, 100);
    $service->prune(false, true, 100);
    $fresh = $draft->fresh();
    expect($fresh->draft_deleted_at)->not->toBeNull()->and($fresh->text_body)->toBeNull()->and($fresh->html_body)->toBeNull()->and($fresh->to_recipients)->toBe([]);
    Queue::assertNothingPushed();
});
