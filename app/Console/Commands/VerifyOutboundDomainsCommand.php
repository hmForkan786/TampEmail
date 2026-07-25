<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Domain;
use App\Services\Outbound\OutboundDomainAuthenticationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

final class VerifyOutboundDomainsCommand extends Command
{
    protected $signature = 'outbound:verify-domains
                            {--limit=50 : Maximum domains to verify in this run}
                            {--domain= : Verify a single domain name}';

    protected $description = 'Verify SPF/DKIM/DMARC readiness for outbound-enabled domains.';

    public function handle(OutboundDomainAuthenticationService $service): int
    {
        $lock = Cache::lock('outbound:verify-domains', 300);
        if (! $lock->get()) {
            $this->warn('Another domain verification run is in progress.');

            return self::SUCCESS;
        }

        try {
            $domainName = trim((string) $this->option('domain'));
            if ($domainName !== '') {
                $domain = Domain::query()->where('domain', strtolower($domainName))->first();
                if ($domain === null) {
                    $this->error('Domain not found.');

                    return self::FAILURE;
                }
                $auth = $service->verify($domain);
                $this->line($domain->domain.': '.$auth->state->value.($auth->failure_code ? ' ('.$auth->failure_code.')' : ''));

                return self::SUCCESS;
            }

            $limit = max(1, (int) $this->option('limit'));
            $results = $service->verifyDue($limit);
            $this->info('Verified '.count($results).' domain(s).');
            foreach ($results as $auth) {
                $name = $auth->domain?->domain ?? $auth->domain_id;
                $this->line($name.': '.$auth->state->value);
            }

            return self::SUCCESS;
        } finally {
            $lock->release();
        }
    }
}
