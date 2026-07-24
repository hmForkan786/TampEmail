<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Inbound\AttachmentScannerHealthService;
use Illuminate\Console\Command;

final class AttachmentScannerHealth extends Command
{
    protected $signature = 'attachments:scanner-health {--json : Print a JSON summary}';
    protected $description = 'Check configured attachment scanner readiness without scanning an attachment.';

    public function handle(AttachmentScannerHealthService $health): int
    {
        try {
            $summary = $health->check();
        } catch (\Throwable) {
            $summary = [
                'backend' => 'unknown',
                'enabled' => false,
                'connection_mode' => 'unknown',
                'timeout_seconds' => 0,
                'byte_limit' => 0,
                'last_successful_check_at' => null,
                'reachable' => false,
                'protocol' => 'error',
                'status' => 'failed',
            ];
        }

        if ($this->option('json')) {
            $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            $this->line('status: '.($summary['status'] ?? 'failed'));
            $this->line('backend: '.($summary['backend'] ?? 'unknown'));
            $this->line('reachable: '.(($summary['reachable'] ?? false) ? 'yes' : 'no'));
            $this->line('protocol: '.($summary['protocol'] ?? 'error'));
        }

        return match ($summary['status'] ?? 'failed') {
            'healthy' => self::SUCCESS,
            'disabled' => 2,
            default => self::FAILURE,
        };
    }
}
