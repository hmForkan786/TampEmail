<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

class CheckPlatformFoundation extends Command
{
    protected $signature = 'platform:check';

    protected $description = 'Check production foundation configuration for the temporary email platform.';

    public function handle(): int
    {
        $checks = [
            'application key is configured' => filled(config('app.key')),
            'production debug mode is disabled' => ! app()->isProduction() || config('app.debug') === false,
            'production app URL is HTTPS' => ! app()->isProduction() || Str::startsWith((string) config('app.url'), 'https://'),
            'security headers are enabled' => config('security.headers.enabled') === true,
            'sessions are encrypted' => config('session.encrypt') === true,
            'redis cache store is selected' => app()->isLocal() || config('cache.default') === 'redis',
            'redis queue connection is selected' => app()->isLocal() || config('queue.default') === 'redis',
            'failed jobs use UUID storage' => config('queue.failed.driver') === 'database-uuids',
            'mail ingestion queue is named' => filled(config('queue.workloads.mail_ingestion')),
            'attachment storage disk is configured' => array_key_exists(config('platform.storage.attachments_disk'), config('filesystems.disks')),
            'message body storage disk is configured' => array_key_exists(config('platform.storage.message_bodies_disk'), config('filesystems.disks')),
            'security log channel is configured' => array_key_exists(config('platform.logs.security_channel'), config('logging.channels')),
            'audit log channel is configured' => array_key_exists(config('platform.logs.audit_channel'), config('logging.channels')),
            'web rate limit is configured' => config('abuse.rate_limits.web_per_minute') > 0,
            'api rate limit is configured' => config('abuse.rate_limits.api_per_minute') > 0,
        ];

        $failed = collect($checks)
            ->filter(fn (bool $passed): bool => ! $passed)
            ->keys();

        foreach ($checks as $label => $passed) {
            $this->line(($passed ? '<info>PASS</info>' : '<error>FAIL</error>')." {$label}");
        }

        if ($failed->isNotEmpty()) {
            $this->newLine();
            $this->error('Platform foundation checks failed.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Platform foundation checks passed.');

        return self::SUCCESS;
    }
}
