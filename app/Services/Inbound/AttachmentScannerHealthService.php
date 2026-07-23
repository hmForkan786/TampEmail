<?php

declare(strict_types=1);

namespace App\Services\Inbound;

use Illuminate\Support\Facades\Cache;

final class AttachmentScannerHealthService
{
    /** @return array<string, mixed> */
    public function check(): array
    {
        $backend = (string) config('attachments.scanner_backend', 'disabled');
        $base = [
            'backend' => $backend,
            'enabled' => $backend !== 'disabled',
            'connection_mode' => config('attachments.clamav.socket') ? 'unix_socket' : 'tcp',
            'timeout_seconds' => (float) config('attachments.clamav.read_timeout_seconds', 30),
            'byte_limit' => (int) config('attachments.max_bytes', 26214400),
            'last_successful_check_at' => Cache::get('attachments.scanner.last_successful_check_at'),
        ];
        if ($backend === 'disabled') return $base + ['reachable' => false, 'protocol' => 'disabled', 'status' => 'disabled'];
        if ($backend !== 'clamav') return $base + ['reachable' => false, 'protocol' => 'unsupported', 'status' => 'misconfigured'];

        $result = app(ClamAvAttachmentScanner::class)->healthCheck();
        if ($result['healthy'] === true) {
            $timestamp = now()->toIso8601String();
            Cache::forever('attachments.scanner.last_successful_check_at', $timestamp);
            $base['last_successful_check_at'] = $timestamp;
        }

        return $base + ['reachable' => $result['reachable'], 'protocol' => $result['protocol'], 'status' => $result['healthy'] ? 'healthy' : 'unavailable'];
    }
}
