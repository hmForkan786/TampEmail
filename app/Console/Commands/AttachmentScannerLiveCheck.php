<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Inbound\AttachmentScannerLiveCheckService;
use Illuminate\Console\Command;
use Throwable;

final class AttachmentScannerLiveCheck extends Command
{
    protected $signature = 'attachments:scanner-live-check {--json : Print a JSON summary}';

    protected $description = 'Verify ClamAV clean and EICAR responses with temporary probes; does not process user attachments.';

    public function handle(AttachmentScannerLiveCheckService $liveCheck): int
    {
        try {
            $summary = $liveCheck->check();

            if (! is_array($summary) || ! is_string($summary['status'] ?? null)) {
                throw new \UnexpectedValueException('Invalid scanner live-check report.');
            }
        } catch (Throwable) {
            $summary = [
                'status' => 'failed',
                'backend' => 'unknown',
                'clean_probe' => 'skipped',
                'infected_probe' => 'skipped',
                'issues' => ['scanner_live_check_unavailable'],
            ];
        }

        if ($this->option('json')) {
            $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            $this->line('status: '.($summary['status'] ?? 'failed'));
            $this->line('backend: '.($summary['backend'] ?? 'unknown'));
            $this->line('clean_probe: '.($summary['clean_probe'] ?? 'skipped'));
            $this->line('infected_probe: '.($summary['infected_probe'] ?? 'skipped'));
            $issues = $summary['issues'] ?? [];
            $this->line('issues: '.(is_array($issues) && $issues !== [] ? implode(',', $issues) : 'none'));
        }

        return match ($summary['status'] ?? 'failed') {
            'healthy' => self::SUCCESS,
            'unavailable' => 2,
            default => self::FAILURE,
        };
    }
}
