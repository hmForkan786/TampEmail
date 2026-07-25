<?php

declare(strict_types=1);

namespace App\Services\Outbound;

use App\Contracts\DnsResolverInterface;
use App\Enums\OutboundDomainAuthState;
use App\Exceptions\OutboundSendException;
use App\Models\Domain;
use App\Models\OutboundDomainAuthentication;
use App\Services\Audit\AuditLogWriter;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Verifies SPF / DKIM / DMARC readiness for outbound-enabled domains.
 *
 * Never mutates public DNS. Never stores private DKIM keys.
 */
final class OutboundDomainAuthenticationService
{
    public function __construct(
        private readonly DnsResolverInterface $dns,
        private readonly AuditLogWriter $audit,
    ) {}

    public function expectedRecordsFor(Domain $domain, ?string $provider = null): array
    {
        $provider = $this->resolveProvider($provider);
        $name = strtolower(trim($domain->domain));

        if ($provider === 'ses') {
            $include = (string) config('outbound.domain_authentication.ses.spf_include', 'include:amazonses.com');
            $tokens = $this->dkimTokens();
            $suffix = (string) config('outbound.domain_authentication.ses.dkim_cname_suffix', 'dkim.amazonses.com');
            $ownership = (string) config('outbound.domain_authentication.ses.ownership_prefix', 'temail-domain-verification=');
            $token = substr(hash('sha256', 'outbound-domain:'.$domain->getKey()), 0, 32);

            $dkim = [];
            foreach ($tokens as $selectorToken) {
                $dkim[] = [
                    'host' => $selectorToken.'._domainkey.'.$name,
                    'type' => 'CNAME',
                    'value' => $selectorToken.'.'.$suffix,
                ];
            }

            return [
                'provider' => 'ses',
                'spf' => 'v=spf1 '.$include.' ~all',
                'dkim' => $dkim,
                'ownership' => $ownership.$token,
                'dmarc' => 'v=DMARC1; p=quarantine; rua=mailto:dmarc@'.$name,
            ];
        }

        // Generic / non-SES: advisory SPF pointing at outbound SMTP host when configured.
        $host = trim((string) config('outbound.smtp.host', ''));
        $spf = $host !== ''
            ? 'v=spf1 a:'.$host.' ~all'
            : null;

        return [
            'provider' => $provider,
            'spf' => $spf,
            'dkim' => [],
            'ownership' => null,
            'dmarc' => 'v=DMARC1; p=quarantine',
        ];
    }

    public function ensureRecord(Domain $domain, ?string $provider = null): OutboundDomainAuthentication
    {
        $provider = $this->resolveProvider($provider);
        $expected = $this->expectedRecordsFor($domain, $provider);

        return OutboundDomainAuthentication::query()->firstOrCreate(
            [
                'domain_id' => $domain->getKey(),
                'provider' => $provider,
            ],
            [
                'state' => OutboundDomainAuthState::Unconfigured,
                'ownership_state' => OutboundDomainAuthState::Unconfigured,
                'spf_state' => OutboundDomainAuthState::Unconfigured,
                'dkim_state' => OutboundDomainAuthState::Unconfigured,
                'dmarc_state' => OutboundDomainAuthState::Unconfigured,
                'expected_spf' => $expected['spf'],
                'expected_dkim' => $expected['dkim'],
                'expected_ownership' => $expected['ownership'],
                'record_version' => 1,
                'next_check_at' => now(),
            ],
        );
    }

    public function verify(Domain $domain, ?string $provider = null, bool $manual = false): OutboundDomainAuthentication
    {
        $provider = $this->resolveProvider($provider);
        $auth = $this->ensureRecord($domain, $provider);
        $expected = $this->expectedRecordsFor($domain, $provider);

        $this->audit->write(
            'outbound.domain_verification_started',
            null,
            $domain,
            null,
            null,
            [
                'provider' => $provider,
                'manual' => $manual,
                'domain' => $domain->domain,
            ],
        );

        $previousState = $auth->state;
        $previousVerifiedAt = $auth->last_verified_at;

        try {
            $ownership = $this->verifyOwnership($domain, $expected['ownership'] ?? null);
            $spf = $this->verifySpf($domain->domain, $expected['spf'] ?? null);
            $dkim = $this->verifyDkim($expected['dkim'] ?? []);
            $dmarc = $this->verifyDmarc($domain->domain);
        } catch (RuntimeException $exception) {
            if ($exception->getMessage() === 'dns_lookup_failed') {
                // Preserve last-known-good during transient DNS failures.
                $auth->forceFill([
                    'last_checked_at' => now(),
                    'next_check_at' => now()->addMinutes(15),
                    'failure_code' => 'dns_transient',
                ])->save();

                if (in_array($previousState, [OutboundDomainAuthState::Verified, OutboundDomainAuthState::Degraded], true)) {
                    return $auth->fresh();
                }

                $auth->forceFill([
                    'state' => OutboundDomainAuthState::Pending,
                    'failure_code' => 'dns_transient',
                ])->save();

                return $auth->fresh();
            }

            throw $exception;
        }

        $overall = $this->composeState($ownership, $spf, $dkim, $dmarc);
        $failure = $this->failureCode($ownership, $spf, $dkim, $dmarc, $overall);

        $auth->forceFill([
            'expected_spf' => $expected['spf'],
            'expected_dkim' => $expected['dkim'],
            'expected_ownership' => $expected['ownership'],
            'ownership_state' => $ownership,
            'spf_state' => $spf,
            'dkim_state' => $dkim,
            'dmarc_state' => $dmarc,
            'state' => $overall,
            'failure_code' => $failure,
            'last_checked_at' => now(),
            'last_verified_at' => in_array($overall, [OutboundDomainAuthState::Verified, OutboundDomainAuthState::Degraded], true)
                ? now()
                : $previousVerifiedAt,
            'next_check_at' => now()->addSeconds((int) config('outbound.domain_authentication.recheck_interval_seconds', 3600)),
        ])->save();

        if ($domain->dns_verified_at === null && $ownership === OutboundDomainAuthState::Verified) {
            $domain->forceFill(['dns_verified_at' => now()])->save();
        }

        $action = match ($overall) {
            OutboundDomainAuthState::Verified => 'outbound.domain_verified',
            OutboundDomainAuthState::Degraded => 'outbound.domain_degraded',
            OutboundDomainAuthState::Failed => 'outbound.domain_verification_failed',
            default => null,
        };

        if ($action !== null) {
            $this->audit->write(
                $action,
                null,
                $domain,
                ['state' => $previousState->value],
                ['state' => $overall->value],
                [
                    'provider' => $provider,
                    'failure_code' => $failure,
                    'spf' => $spf->value,
                    'dkim' => $dkim->value,
                    'dmarc' => $dmarc->value,
                    'ownership' => $ownership->value,
                ],
            );
        }

        return $auth->fresh();
    }

    public function assertDomainReady(Domain $domain): void
    {
        if (! config('outbound.domain_authentication.enforce', true)) {
            return;
        }

        $expected = $this->expectedRecordsFor($domain);
        $hasMandatory = ($expected['spf'] ?? null) !== null || ($expected['dkim'] ?? []) !== [];
        if (! $hasMandatory) {
            // No provider DNS expectations configured yet — do not block send path.
            return;
        }

        $auth = OutboundDomainAuthentication::query()
            ->where('domain_id', $domain->getKey())
            ->where('provider', $this->resolveProvider())
            ->first();

        if ($auth === null) {
            $auth = $this->ensureRecord($domain);
        }

        if ($auth->allowsSending()) {
            return;
        }

        $code = match ($auth->state) {
            OutboundDomainAuthState::Failed => 'domain_auth_failed',
            OutboundDomainAuthState::Pending => 'domain_auth_pending',
            OutboundDomainAuthState::Unconfigured => 'domain_auth_unconfigured',
            default => 'domain_auth_not_ready',
        };

        throw new OutboundSendException(
            $code,
            'Domain authentication is not ready for outbound email.',
            403,
        );
    }

    public function manualRecheck(Domain $domain, string $actorId): OutboundDomainAuthentication
    {
        $key = 'outbound.domain_auth.recheck:'.$domain->getKey();
        $ttl = max(30, (int) config('outbound.domain_authentication.manual_recheck_cooldown_seconds', 60));
        if (! Cache::add($key, 1, $ttl)) {
            throw new OutboundSendException(
                'domain_auth_rate_limited',
                'Domain authentication recheck was rate limited.',
                429,
            );
        }

        return $this->verify($domain, null, true);
    }

    /**
     * @return list<OutboundDomainAuthentication>
     */
    public function verifyDue(int $limit = 50): array
    {
        $provider = $this->resolveProvider();
        $limit = max(1, min(200, $limit));

        $domains = Domain::query()
            ->where('outbound_enabled', true)
            ->where('is_active', true)
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $results = [];
        foreach ($domains as $domain) {
            $auth = $this->ensureRecord($domain, $provider);
            if ($auth->next_check_at !== null && $auth->next_check_at->isFuture()) {
                continue;
            }
            $results[] = $this->verify($domain, $provider);
        }

        return $results;
    }

    private function resolveProvider(?string $provider = null): string
    {
        $configured = strtolower(trim((string) ($provider ?: config('outbound.provider', 'generic'))));
        if (! in_array($configured, ['generic', 'ses'], true)) {
            return 'generic';
        }

        return $configured;
    }

    /**
     * @return list<string>
     */
    private function dkimTokens(): array
    {
        $raw = (string) config('outbound.domain_authentication.ses.dkim_tokens', '');
        $tokens = array_values(array_filter(array_map(
            static fn (string $token): string => preg_replace('/[^a-zA-Z0-9]/', '', trim($token)) ?? '',
            explode(',', $raw),
        )));

        return $tokens;
    }

    private function verifyOwnership(Domain $domain, ?string $expected): OutboundDomainAuthState
    {
        if ($expected === null || $expected === '') {
            return $domain->dns_verified_at !== null
                ? OutboundDomainAuthState::Verified
                : OutboundDomainAuthState::Unconfigured;
        }

        $records = $this->dns->lookupTxt($domain->domain);
        foreach ($records as $record) {
            if (hash_equals(strtolower($expected), strtolower($record))) {
                return OutboundDomainAuthState::Verified;
            }
        }

        return OutboundDomainAuthState::Pending;
    }

    private function verifySpf(string $domain, ?string $expected): OutboundDomainAuthState
    {
        if ($expected === null || $expected === '') {
            return OutboundDomainAuthState::Unconfigured;
        }

        $records = $this->dns->lookupTxt($domain);
        $spfRecords = array_values(array_filter(
            $records,
            static fn (string $record): bool => str_starts_with(strtolower($record), 'v=spf1'),
        ));

        if ($spfRecords === []) {
            return OutboundDomainAuthState::Pending;
        }

        if (count($spfRecords) > 1) {
            return OutboundDomainAuthState::Failed;
        }

        $spf = $spfRecords[0];
        if (str_contains($spf, '+all')) {
            return OutboundDomainAuthState::Failed;
        }

        $include = (string) config('outbound.domain_authentication.ses.spf_include', 'include:amazonses.com');
        $provider = $this->resolveProvider();
        if ($provider === 'ses' && ! str_contains($spf, strtolower($include))) {
            return OutboundDomainAuthState::Failed;
        }

        if ($provider !== 'ses') {
            $host = strtolower(trim((string) config('outbound.smtp.host', '')));
            if ($host !== '' && ! str_contains($spf, $host) && ! str_contains($spf, 'a:'.$host)) {
                return OutboundDomainAuthState::Failed;
            }
        }

        return OutboundDomainAuthState::Verified;
    }

    /**
     * @param  list<array{host: string, type: string, value: string}>  $expected
     */
    private function verifyDkim(array $expected): OutboundDomainAuthState
    {
        if ($expected === []) {
            return OutboundDomainAuthState::Unconfigured;
        }

        $pending = false;
        foreach ($expected as $record) {
            $host = (string) ($record['host'] ?? '');
            $value = rtrim(strtolower((string) ($record['value'] ?? '')), '.');
            if ($host === '' || $value === '') {
                return OutboundDomainAuthState::Failed;
            }

            $cnames = $this->dns->lookupCname($host);
            if ($cnames === []) {
                // Also accept TXT public keys when provider uses TXT publishing.
                $txt = $this->dns->lookupTxt($host);
                $joined = strtolower(implode('', $txt));
                if ($joined !== '' && str_contains($joined, 'v=dkim1') && str_contains($joined, 'p=') && ! str_contains($joined, 'p=;')) {
                    continue;
                }
                $pending = true;

                continue;
            }

            $normalized = array_map(
                static fn (string $target): string => rtrim(strtolower($target), '.'),
                $cnames,
            );
            if (! in_array($value, $normalized, true)) {
                return OutboundDomainAuthState::Failed;
            }
        }

        return $pending ? OutboundDomainAuthState::Pending : OutboundDomainAuthState::Verified;
    }

    private function verifyDmarc(string $domain): OutboundDomainAuthState
    {
        $records = $this->dns->lookupTxt('_dmarc.'.$domain);
        $dmarcRecords = array_values(array_filter(
            $records,
            static fn (string $record): bool => str_starts_with(strtolower($record), 'v=dmarc1'),
        ));

        if ($dmarcRecords === []) {
            return OutboundDomainAuthState::Pending;
        }

        if (count($dmarcRecords) > 1) {
            return OutboundDomainAuthState::Failed;
        }

        $policy = $this->dmarcPolicy($dmarcRecords[0]);
        if ($policy === null) {
            return OutboundDomainAuthState::Failed;
        }

        if (in_array($policy, ['quarantine', 'reject'], true)) {
            return OutboundDomainAuthState::Verified;
        }

        if ($policy === 'none') {
            return OutboundDomainAuthState::Degraded;
        }

        return OutboundDomainAuthState::Failed;
    }

    private function dmarcPolicy(string $record): ?string
    {
        if (preg_match('/(?:^|;)\s*p\s*=\s*(none|quarantine|reject)\s*(?:;|$)/i', $record, $matches) !== 1) {
            return null;
        }

        return strtolower($matches[1]);
    }

    private function composeState(
        OutboundDomainAuthState $ownership,
        OutboundDomainAuthState $spf,
        OutboundDomainAuthState $dkim,
        OutboundDomainAuthState $dmarc,
    ): OutboundDomainAuthState {
        $mandatory = [$spf, $dkim];
        foreach ($mandatory as $state) {
            if ($state === OutboundDomainAuthState::Failed) {
                return OutboundDomainAuthState::Failed;
            }
        }

        if ($spf === OutboundDomainAuthState::Unconfigured && $dkim === OutboundDomainAuthState::Unconfigured) {
            return OutboundDomainAuthState::Unconfigured;
        }

        foreach ($mandatory as $state) {
            if (in_array($state, [OutboundDomainAuthState::Pending, OutboundDomainAuthState::Unconfigured], true)) {
                return OutboundDomainAuthState::Pending;
            }
        }

        if ($dmarc === OutboundDomainAuthState::Failed) {
            return OutboundDomainAuthState::Failed;
        }

        if (in_array($dmarc, [OutboundDomainAuthState::Pending, OutboundDomainAuthState::Degraded, OutboundDomainAuthState::Unconfigured], true)) {
            return OutboundDomainAuthState::Degraded;
        }

        return OutboundDomainAuthState::Verified;
    }

    private function failureCode(
        OutboundDomainAuthState $ownership,
        OutboundDomainAuthState $spf,
        OutboundDomainAuthState $dkim,
        OutboundDomainAuthState $dmarc,
        OutboundDomainAuthState $overall,
    ): ?string {
        return match ($overall) {
            OutboundDomainAuthState::Failed => match (true) {
                $spf === OutboundDomainAuthState::Failed => 'spf_invalid',
                $dkim === OutboundDomainAuthState::Failed => 'dkim_invalid',
                $dmarc === OutboundDomainAuthState::Failed => 'dmarc_invalid',
                default => 'domain_auth_failed',
            },
            OutboundDomainAuthState::Pending => 'dns_pending',
            OutboundDomainAuthState::Degraded => 'dmarc_weak',
            OutboundDomainAuthState::Unconfigured => 'unconfigured',
            default => null,
        };
    }
}
