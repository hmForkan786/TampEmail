<?php

declare(strict_types=1);

use App\Contracts\DnsResolverInterface;
use App\Enums\OutboundDomainAuthState;
use App\Exceptions\OutboundSendException;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\OutboundDomainAuthentication;
use App\Models\User;
use App\Services\Dns\FakeDnsResolver;
use App\Services\Outbound\OutboundDomainAuthenticationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

function seedAuthDomain(string $name = 'auth.example.test'): Domain
{
    return Domain::query()->create([
        'domain' => $name,
        'display_name' => 'Auth',
        'is_active' => true,
        'is_public' => true,
        'allow_registration' => true,
        'is_healthy' => true,
        'outbound_enabled' => true,
        'retention_hours' => 24,
    ]);
}

beforeEach(function (): void {
    $this->fakeDns = new FakeDnsResolver;
    $this->app->instance(DnsResolverInterface::class, $this->fakeDns);

    config([
        'outbound.provider' => 'ses',
        'outbound.domain_authentication.enforce' => true,
        'outbound.domain_authentication.allow_degraded_dmarc' => true,
        'outbound.domain_authentication.ses.dkim_tokens' => 'aaa,bbb',
        'outbound.domain_authentication.ses.spf_include' => 'include:amazonses.com',
        'outbound.domain_authentication.manual_recheck_cooldown_seconds' => 60,
    ]);
    Cache::flush();
});

it('marks unconfigured domains without expected visibility as pending or unconfigured', function (): void {
    $domain = seedAuthDomain('empty.example.test');
    $auth = app(OutboundDomainAuthenticationService::class)->verify($domain);

    expect($auth->state)->toBe(OutboundDomainAuthState::Pending)
        ->and($auth->spf_state)->toBe(OutboundDomainAuthState::Pending)
        ->and($auth->expected_spf)->toContain('include:amazonses.com')
        ->and($auth->expected_dkim)->toHaveCount(2)
        ->and(json_encode($auth->expected_dkim))->not->toContain('private');
});

it('verifies valid SPF DKIM and DMARC', function (): void {
    $domain = seedAuthDomain('good.example.test');
    $this->fakeDns->setTxt('good.example.test', [
        'v=spf1 include:amazonses.com ~all',
        'temail-domain-verification='.substr(hash('sha256', 'outbound-domain:'.$domain->getKey()), 0, 32),
    ]);
    $this->fakeDns->setCname('aaa._domainkey.good.example.test', ['aaa.dkim.amazonses.com']);
    $this->fakeDns->setCname('bbb._domainkey.good.example.test', ['bbb.dkim.amazonses.com']);
    $this->fakeDns->setTxt('_dmarc.good.example.test', ['v=DMARC1; p=reject']);

    $auth = app(OutboundDomainAuthenticationService::class)->verify($domain);

    expect($auth->state)->toBe(OutboundDomainAuthState::Verified)
        ->and($auth->spf_state)->toBe(OutboundDomainAuthState::Verified)
        ->and($auth->dkim_state)->toBe(OutboundDomainAuthState::Verified)
        ->and($auth->dmarc_state)->toBe(OutboundDomainAuthState::Verified)
        ->and(AuditLog::query()->where('action', 'outbound.domain_verified')->exists())->toBeTrue();
});

it('fails duplicate SPF wrong include and malformed DKIM', function (): void {
    $domain = seedAuthDomain('bad-spf.example.test');
    $this->fakeDns->setTxt('bad-spf.example.test', [
        'v=spf1 include:amazonses.com ~all',
        'v=spf1 include:other.example ~all',
    ]);
    $auth = app(OutboundDomainAuthenticationService::class)->verify($domain);
    expect($auth->spf_state)->toBe(OutboundDomainAuthState::Failed)
        ->and($auth->state)->toBe(OutboundDomainAuthState::Failed)
        ->and($auth->failure_code)->toBe('spf_invalid');

    $domain2 = seedAuthDomain('wrong-spf.example.test');
    $this->fakeDns->setTxt('wrong-spf.example.test', ['v=spf1 include:mailgun.org ~all']);
    $auth2 = app(OutboundDomainAuthenticationService::class)->verify($domain2);
    expect($auth2->spf_state)->toBe(OutboundDomainAuthState::Failed);

    $domain3 = seedAuthDomain('bad-dkim.example.test');
    $this->fakeDns->setTxt('bad-dkim.example.test', ['v=spf1 include:amazonses.com ~all']);
    $this->fakeDns->setCname('aaa._domainkey.bad-dkim.example.test', ['evil.example']);
    $this->fakeDns->setCname('bbb._domainkey.bad-dkim.example.test', ['bbb.dkim.amazonses.com']);
    $auth3 = app(OutboundDomainAuthenticationService::class)->verify($domain3);
    expect($auth3->dkim_state)->toBe(OutboundDomainAuthState::Failed)
        ->and($auth3->failure_code)->toBe('dkim_invalid');
});

it('accepts DKIM TXT public keys and trailing-dot CNAMEs', function (): void {
    $domain = seedAuthDomain('txt-dkim.example.test');
    $this->fakeDns->setTxt('txt-dkim.example.test', ['v=spf1 include:amazonses.com ~all']);
    $this->fakeDns->setTxt('aaa._domainkey.txt-dkim.example.test', ['v=DKIM1; k=rsa; p=MIGfMA0GCSq']);
    $this->fakeDns->setTxt('bbb._domainkey.txt-dkim.example.test', ['v=DKIM1; k=rsa; p=MIGfMA0GCSq']);
    $this->fakeDns->setTxt('_dmarc.txt-dkim.example.test', ['v=DMARC1; p=quarantine']);
    $auth = app(OutboundDomainAuthenticationService::class)->verify($domain);
    expect($auth->dkim_state)->toBe(OutboundDomainAuthState::Verified);

    $domain2 = seedAuthDomain('dot.example.test');
    $this->fakeDns->setTxt('dot.example.test', ['v=spf1 include:amazonses.com ~all']);
    $this->fakeDns->setCname('aaa._domainkey.dot.example.test', ['aaa.dkim.amazonses.com.']);
    $this->fakeDns->setCname('bbb._domainkey.dot.example.test', ['bbb.dkim.amazonses.com.']);
    $this->fakeDns->setTxt('_dmarc.dot.example.test', ['v=DMARC1; p=quarantine']);
    $auth2 = app(OutboundDomainAuthenticationService::class)->verify($domain2);
    expect($auth2->state)->toBe(OutboundDomainAuthState::Verified);
});

it('degrades on missing or weak DMARC and fails invalid DMARC', function (): void {
    $domain = seedAuthDomain('weak.example.test');
    $this->fakeDns->setTxt('weak.example.test', ['v=spf1 include:amazonses.com ~all']);
    $this->fakeDns->setCname('aaa._domainkey.weak.example.test', ['aaa.dkim.amazonses.com']);
    $this->fakeDns->setCname('bbb._domainkey.weak.example.test', ['bbb.dkim.amazonses.com']);
    $auth = app(OutboundDomainAuthenticationService::class)->verify($domain);
    expect($auth->state)->toBe(OutboundDomainAuthState::Degraded)
        ->and(AuditLog::query()->where('action', 'outbound.domain_degraded')->exists())->toBeTrue();

    $this->fakeDns->setTxt('_dmarc.weak.example.test', ['v=DMARC1; p=none']);
    $auth2 = app(OutboundDomainAuthenticationService::class)->verify($domain);
    expect($auth2->state)->toBe(OutboundDomainAuthState::Degraded)
        ->and($auth2->dmarc_state)->toBe(OutboundDomainAuthState::Degraded);

    $this->fakeDns->setTxt('_dmarc.weak.example.test', ['v=DMARC1; p=invalid']);
    $auth3 = app(OutboundDomainAuthenticationService::class)->verify($domain);
    expect($auth3->state)->toBe(OutboundDomainAuthState::Failed)
        ->and($auth3->failure_code)->toBe('dmarc_invalid');
});

it('preserves last-known-good on transient DNS failure', function (): void {
    $domain = seedAuthDomain('transient.example.test');
    $this->fakeDns->setTxt('transient.example.test', ['v=spf1 include:amazonses.com ~all']);
    $this->fakeDns->setCname('aaa._domainkey.transient.example.test', ['aaa.dkim.amazonses.com']);
    $this->fakeDns->setCname('bbb._domainkey.transient.example.test', ['bbb.dkim.amazonses.com']);
    $this->fakeDns->setTxt('_dmarc.transient.example.test', ['v=DMARC1; p=reject']);
    $service = app(OutboundDomainAuthenticationService::class);
    $service->verify($domain);

    $this->fakeDns->fail('transient.example.test');
    $auth = $service->verify($domain);
    expect($auth->state)->toBe(OutboundDomainAuthState::Verified)
        ->and($auth->failure_code)->toBe('dns_transient');
});

it('rate limits manual recheck and blocks send on failed auth', function (): void {
    $domain = seedAuthDomain('gate.example.test');
    $this->fakeDns->setTxt('gate.example.test', [
        'v=spf1 include:amazonses.com ~all',
        'v=spf1 include:other ~all',
    ]);
    $service = app(OutboundDomainAuthenticationService::class);
    $service->verify($domain);

    expect(fn () => $service->assertDomainReady($domain))
        ->toThrow(OutboundSendException::class);

    $admin = User::factory()->platformAdmin()->create();
    $service->manualRecheck($domain, (string) $admin->getKey());
    expect(fn () => $service->manualRecheck($domain, (string) $admin->getKey()))
        ->toThrow(OutboundSendException::class);
});

it('allows send under degraded DMARC policy', function (): void {
    $domain = seedAuthDomain('degraded-send.example.test');
    $this->fakeDns->setTxt('degraded-send.example.test', ['v=spf1 include:amazonses.com ~all']);
    $this->fakeDns->setCname('aaa._domainkey.degraded-send.example.test', ['aaa.dkim.amazonses.com']);
    $this->fakeDns->setCname('bbb._domainkey.degraded-send.example.test', ['bbb.dkim.amazonses.com']);
    $this->fakeDns->setTxt('_dmarc.degraded-send.example.test', ['v=DMARC1; p=none']);
    $service = app(OutboundDomainAuthenticationService::class);
    $service->verify($domain);
    $service->assertDomainReady($domain);
    expect(true)->toBeTrue();
});

it('runs scheduled verification with lock and help text', function (): void {
    $this->artisan('outbound:verify-domains', ['--help' => true])->assertSuccessful();

    $domain = seedAuthDomain('sched.example.test');
    $this->fakeDns->setTxt('sched.example.test', ['v=spf1 include:amazonses.com ~all']);
    $this->fakeDns->setCname('aaa._domainkey.sched.example.test', ['aaa.dkim.amazonses.com']);
    $this->fakeDns->setCname('bbb._domainkey.sched.example.test', ['bbb.dkim.amazonses.com']);
    $this->fakeDns->setTxt('_dmarc.sched.example.test', ['v=DMARC1; p=quarantine']);

    $this->artisan('outbound:verify-domains', ['--limit' => 10])->assertSuccessful();
    expect(OutboundDomainAuthentication::query()->where('domain_id', $domain->getKey())->first()?->state)
        ->toBe(OutboundDomainAuthState::Verified);
});
