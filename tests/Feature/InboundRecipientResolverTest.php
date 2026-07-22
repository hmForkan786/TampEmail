<?php

use App\DTOs\Inbound\RecipientInput;
use App\Enums\InboundRoutingCode;
use App\Enums\InboxType;
use App\Models\Domain;
use App\Models\Inbox;
use App\Models\User;
use App\Services\Inbound\InboundRecipientResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function routingDomain(array $overrides = []): Domain
{
    return Domain::query()->create(array_merge([
        'domain' => 'route-'.bin2hex(random_bytes(4)).'.example.test', 'display_name' => 'Routing domain',
        'is_active' => true, 'is_public' => true, 'allow_registration' => true, 'is_healthy' => true,
        'priority' => 1, 'retention_hours' => 24,
    ], $overrides));
}

function routingInbox(Domain $domain, array $overrides = []): Inbox
{
    $local = $overrides['local_part'] ?? 'hello';
    return Inbox::query()->create(array_merge([
        'domain_id' => $domain->id, 'local_part' => $local, 'full_address' => $local.'@'.$domain->domain,
        'inbox_type' => InboxType::Temporary, 'is_active' => true, 'expires_at' => now()->addHour(),
    ], $overrides));
}

it('resolves a user-owned address and normalizes the domain', function (): void {
    $domain = routingDomain(['domain' => 'example.test']);
    $inbox = routingInbox($domain, ['user_id' => User::factory()->create()->id]);
    $result = app(InboundRecipientResolver::class)->resolve('hello@EXAMPLE.TEST');
    expect($result->code)->toBe(InboundRoutingCode::Resolved)->and($result->inboxId)->toBe($inbox->id)->and($result->isAnonymous)->toBeFalse();
});

it('resolves an anonymous public address', function (): void {
    $domain = routingDomain(['domain' => 'public.test']); routingInbox($domain, ['full_address' => 'public@public.test']);
    $result = app(InboundRecipientResolver::class)->resolve(new RecipientInput('public@public.test', true));
    expect($result->code)->toBe(InboundRoutingCode::Resolved)->and($result->isAnonymous)->toBeTrue();
});

it('rejects malformed, whitespace and control-character recipients', function (string $raw): void {
    expect(app(InboundRecipientResolver::class)->resolve($raw)->code)->toBe(InboundRoutingCode::InvalidAddress);
})->with(['missing-at', 'a@@example.test', ' a@example.test', "a\r\n@example.test", '@example.test']);

it('returns stable domain and inbox state results', function (): void {
    $resolver = app(InboundRecipientResolver::class);
    expect($resolver->resolve('none@missing.test')->code)->toBe(InboundRoutingCode::UnknownDomain);
    $inactive = routingDomain(['domain' => 'inactive.test', 'is_active' => false]); routingInbox($inactive, ['full_address' => 'x@inactive.test']);
    expect($resolver->resolve('x@inactive.test')->code)->toBe(InboundRoutingCode::InactiveDomain);
    $domain = routingDomain(['domain' => 'states.test']);
    expect($resolver->resolve('none@states.test')->code)->toBe(InboundRoutingCode::UnknownInbox);
    routingInbox($domain, ['full_address' => 'old@states.test', 'expires_at' => now()->subMinute()]);
    expect($resolver->resolve('old@states.test')->code)->toBe(InboundRoutingCode::Expired);
});

it('rejects inactive and non-public anonymous inboxes', function (): void {
    $domain = routingDomain(['domain' => 'private.test', 'is_public' => false]);
    routingInbox($domain, ['full_address' => 'inactive@private.test', 'is_active' => false]);
    expect(app(InboundRecipientResolver::class)->resolve(new RecipientInput('inactive@private.test'))->code)->toBe(InboundRoutingCode::InactiveInbox);
    $public = routingDomain(['domain' => 'disabled.test', 'is_public' => false]); routingInbox($public, ['full_address' => 'anon@disabled.test']);
    expect(app(InboundRecipientResolver::class)->resolve(new RecipientInput('anon@disabled.test'))->code)->toBe(InboundRoutingCode::PublicIngressDisabled);
});
