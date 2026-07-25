<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Outbound\SesOutboundProviderEventParser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Confirms a pending Amazon SNS subscription for SES outbound webhooks.
 *
 * Never confirms arbitrary URLs — only HTTPS sns.*.amazonaws.com endpoints
 * previously verified via SNS signature and cached by the webhook parser.
 */
final class ConfirmSesSubscriptionCommand extends Command
{
    protected $signature = 'outbound:confirm-ses-subscription
                            {--from-cache : Confirm the pending SubscribeURL cached by the webhook}
                            {--url= : Explicit SubscribeURL (must be an official SNS HTTPS endpoint)}
                            {--dry-run : Validate without performing the confirmation GET}';

    protected $description = 'Confirm an Amazon SNS subscription for SES outbound delivery events.';

    public function handle(SesOutboundProviderEventParser $parser): int
    {
        if (! array_key_exists('ses', config('outbound.delivery_webhook.providers', []))) {
            $this->error('SES outbound provider is not configured.');

            return self::FAILURE;
        }

        $url = trim((string) $this->option('url'));
        if ($this->option('from-cache')) {
            $pending = Cache::get('outbound.ses.pending_subscription');
            if (! is_array($pending) || empty($pending['subscribe_url'])) {
                $this->error('No pending SES SNS subscription is cached.');

                return self::FAILURE;
            }
            $url = (string) $pending['subscribe_url'];
            $this->line('topic_arn: '.((string) ($pending['topic_arn'] ?? '')));
        }

        if ($url === '') {
            $this->error('Provide --from-cache or --url=.');

            return self::FAILURE;
        }

        if (! $parser->isSafeSubscribeUrl($url)) {
            $this->error('SubscribeURL is not an allowed SNS HTTPS endpoint.');

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $this->info('SubscribeURL is valid. Dry-run only; no confirmation request was sent.');

            return self::SUCCESS;
        }

        try {
            $response = Http::withOptions([
                'allow_redirects' => false,
                'verify' => true,
                'timeout' => 10,
                'connect_timeout' => 5,
            ])->get($url);
        } catch (\Throwable) {
            $this->error('Failed to reach the SNS confirmation endpoint.');

            return self::FAILURE;
        }

        if (! $response->successful()) {
            $this->error('SNS confirmation request failed with HTTP '.$response->status().'.');

            return self::FAILURE;
        }

        Cache::forget('outbound.ses.pending_subscription');
        $this->info('SNS subscription confirmed.');

        return self::SUCCESS;
    }
}
