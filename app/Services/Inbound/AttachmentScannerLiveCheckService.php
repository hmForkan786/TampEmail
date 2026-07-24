<?php

declare(strict_types=1);

namespace App\Services\Inbound;

use App\Contracts\AttachmentScannerInterface;
use App\DTOs\Attachment\AttachmentScanRequest;
use App\DTOs\Attachment\AttachmentScanResultData;
use App\Enums\AttachmentScanResult;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class AttachmentScannerLiveCheckService
{
    public const EICAR = 'X5O!P%@AP[4\\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*';

    public const CLEAN_PAYLOAD = 'temail-scanner-live-check-clean';

    /** @param null|callable(): AttachmentScannerInterface $scannerFactory */
    public function __construct(private $scannerFactory = null) {}

    /**
     * @return array{
     *     status: string,
     *     backend: string,
     *     clean_probe: string,
     *     infected_probe: string,
     *     issues: list<string>
     * }
     */
    public function check(): array
    {
        $backend = strtolower(trim((string) config('attachments.scanner_backend', 'disabled')));
        $backend = $backend === '' ? 'disabled' : $backend;

        $base = [
            'backend' => $backend,
            'clean_probe' => 'skipped',
            'infected_probe' => 'skipped',
            'issues' => [],
        ];

        if ($backend === 'disabled') {
            return array_merge($base, ['status' => 'disabled', 'issues' => ['scanner_backend_disabled']]);
        }

        if ($backend !== 'clamav') {
            return array_merge($base, ['status' => 'misconfigured', 'issues' => ['scanner_backend_unsupported']]);
        }

        $disk = (string) config('platform.storage.attachments_disk', 'attachments');

        if ($disk === '' || ! is_array(config("filesystems.disks.{$disk}"))) {
            return array_merge($base, ['status' => 'misconfigured', 'issues' => ['attachments_disk_misconfigured']]);
        }

        $token = bin2hex(random_bytes(8));
        $cleanPath = ".scanner-live-check/{$token}-clean.txt";
        $infectedPath = ".scanner-live-check/{$token}-eicar.txt";
        $paths = [$cleanPath, $infectedPath];

        try {
            $filesystem = Storage::disk($disk);
            $scanner = $this->scanner();

            $filesystem->put($cleanPath, self::CLEAN_PAYLOAD);
            $cleanResult = $scanner->scan($this->request($disk, $cleanPath, self::CLEAN_PAYLOAD));
            $cleanProbe = $this->probeLabel($cleanResult);
            $base['clean_probe'] = $cleanProbe;

            if ($this->isTransportFailure($cleanResult)) {
                return array_merge($base, [
                    'status' => 'unavailable',
                    'issues' => ['clean_probe_unavailable'],
                ]);
            }

            if ($cleanResult->result !== AttachmentScanResult::Clean) {
                return array_merge($base, [
                    'status' => 'failed',
                    'issues' => ['clean_probe_unexpected_result'],
                ]);
            }

            $filesystem->put($infectedPath, self::EICAR);
            $infectedResult = $scanner->scan($this->request($disk, $infectedPath, self::EICAR));
            $infectedProbe = $this->probeLabel($infectedResult);
            $base['infected_probe'] = $infectedProbe;

            if ($this->isTransportFailure($infectedResult)) {
                return array_merge($base, [
                    'status' => 'unavailable',
                    'issues' => ['infected_probe_unavailable'],
                ]);
            }

            if ($infectedResult->result !== AttachmentScanResult::Infected) {
                return array_merge($base, [
                    'status' => 'failed',
                    'issues' => ['infected_probe_unexpected_result'],
                ]);
            }

            return array_merge($base, [
                'status' => 'healthy',
                'issues' => [],
            ]);
        } catch (Throwable) {
            return array_merge($base, [
                'status' => 'failed',
                'issues' => ['scanner_live_check_unavailable'],
            ]);
        } finally {
            $this->cleanup($disk, $paths);
        }
    }

    private function scanner(): AttachmentScannerInterface
    {
        if (is_callable($this->scannerFactory)) {
            return ($this->scannerFactory)();
        }

        return app(AttachmentScannerInterface::class);
    }

    private function request(string $disk, string $path, string $payload): AttachmentScanRequest
    {
        return new AttachmentScanRequest(
            $disk,
            $path,
            strlen($payload),
            hash('sha256', $payload),
            'text/plain',
        );
    }

    private function probeLabel(AttachmentScanResultData $result): string
    {
        return match ($result->result) {
            AttachmentScanResult::Clean => 'clean',
            AttachmentScanResult::Infected => 'infected',
            AttachmentScanResult::Failed => $this->failedProbeLabel($result),
        };
    }

    private function failedProbeLabel(AttachmentScanResultData $result): string
    {
        $version = strtolower((string) $result->scannerVersion);

        return match (true) {
            str_contains($version, 'unavailable') => 'unavailable',
            str_contains($version, 'timeout') => 'timeout',
            str_contains($version, 'malformed') => 'malformed',
            default => 'failed',
        };
    }

    private function isTransportFailure(AttachmentScanResultData $result): bool
    {
        if ($result->result !== AttachmentScanResult::Failed) {
            return false;
        }

        $version = strtolower((string) $result->scannerVersion);

        return str_contains($version, 'unavailable')
            || str_contains($version, 'timeout')
            || str_contains($version, 'malformed')
            || str_contains($version, 'protocol')
            || str_contains($version, 'write')
            || str_contains($version, 'error');
    }

    /** @param list<string> $paths */
    private function cleanup(string $disk, array $paths): void
    {
        try {
            $filesystem = Storage::disk($disk);

            foreach ($paths as $path) {
                if ($filesystem->exists($path)) {
                    $filesystem->delete($path);
                }
            }

            if (method_exists($filesystem, 'deleteDirectory')) {
                $filesystem->deleteDirectory('.scanner-live-check');
            }
        } catch (Throwable) {
            // Cleanup is best-effort and must not change the reported status.
        }
    }
}
