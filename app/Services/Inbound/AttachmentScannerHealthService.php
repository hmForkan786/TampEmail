<?php

declare(strict_types=1);

namespace App\Services\Inbound;

use Illuminate\Support\Facades\Cache;

final class AttachmentScannerHealthService
{
    /** @param null|callable(): array{healthy: bool, reachable: bool, protocol: string} $probe */
    public function __construct(private $probe = null) {}

    /** @return array<string, mixed> */
    public function check(): array
    {
        $backend = strtolower(trim((string) config('attachments.scanner_backend', 'disabled')));
        $connectTimeout = (float) config('attachments.clamav.connect_timeout_seconds', 5);
        $readTimeout = (float) config('attachments.clamav.read_timeout_seconds', config('attachments.clamav.timeout_seconds', 30));
        $byteLimit = (int) config('attachments.max_bytes', 26214400);
        $socket = trim((string) config('attachments.clamav.socket', ''));
        $host = trim((string) config('attachments.clamav.host', '127.0.0.1'));
        $port = (int) config('attachments.clamav.port', 3310);

        $base = [
            'backend' => $backend === '' ? 'disabled' : $backend,
            'enabled' => $backend !== '' && $backend !== 'disabled',
            'connection_mode' => $socket !== '' ? 'unix_socket' : 'tcp',
            'timeout_seconds' => $readTimeout,
            'byte_limit' => $byteLimit,
            'last_successful_check_at' => Cache::get('attachments.scanner.last_successful_check_at'),
        ];

        if ($backend === '' || $backend === 'disabled') {
            return $base + ['reachable' => false, 'protocol' => 'disabled', 'status' => 'disabled'];
        }

        if ($backend !== 'clamav') {
            return $base + ['reachable' => false, 'protocol' => 'unsupported', 'status' => 'misconfigured'];
        }

        if ($byteLimit <= 0 || $connectTimeout < 1 || $connectTimeout > 120 || $readTimeout < 1 || $readTimeout > 120) {
            return $base + ['reachable' => false, 'protocol' => 'invalid_config', 'status' => 'misconfigured'];
        }

        if ($socket === '' && ($host === '' || $port < 1 || $port > 65535)) {
            return $base + ['reachable' => false, 'protocol' => 'invalid_config', 'status' => 'misconfigured'];
        }

        try {
            $result = $this->probe();
        } catch (\Throwable) {
            return $base + ['reachable' => false, 'protocol' => 'error', 'status' => 'failed'];
        }

        if (($result['healthy'] ?? false) === true) {
            $timestamp = now()->toIso8601String();
            Cache::forever('attachments.scanner.last_successful_check_at', $timestamp);
            $base['last_successful_check_at'] = $timestamp;

            return $base + [
                'reachable' => true,
                'protocol' => (string) ($result['protocol'] ?? 'pong'),
                'status' => 'healthy',
            ];
        }

        return $base + [
            'reachable' => (bool) ($result['reachable'] ?? false),
            'protocol' => (string) ($result['protocol'] ?? 'unavailable'),
            'status' => 'unavailable',
        ];
    }

    /** @return array{healthy: bool, reachable: bool, protocol: string} */
    private function probe(): array
    {
        if (is_callable($this->probe)) {
            /** @var array{healthy: bool, reachable: bool, protocol: string} $result */
            $result = ($this->probe)();

            return $result;
        }

        return app(ClamAvAttachmentScanner::class)->healthCheck();
    }
}
