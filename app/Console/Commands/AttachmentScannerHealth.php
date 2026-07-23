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
            $summary = ['status' => 'failed', 'scanner' => ['status' => 'unavailable']];
        }
        if ($this->option('json')) {
            $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            $this->line('status: '.($summary['status'] ?? 'failed'));
            $this->line('scanner: '.($summary['scanner']['status'] ?? 'unavailable'));
        }

        return match ($summary['status']) {
            'healthy' => self::SUCCESS,
            'disabled' => 2,
            default => self::FAILURE,
        };
    }
}
