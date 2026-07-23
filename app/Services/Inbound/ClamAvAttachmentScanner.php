<?php

declare(strict_types=1);

namespace App\Services\Inbound;

use App\Contracts\AttachmentScannerInterface;
use App\DTOs\Attachment\AttachmentScanRequest;
use App\DTOs\Attachment\AttachmentScanResultData;
use App\Enums\AttachmentScanResult;
use Illuminate\Support\Facades\Storage;

/** Minimal, stream-only ClamAV INSTREAM protocol adapter. */
final class ClamAvAttachmentScanner implements AttachmentScannerInterface
{
    /** @return array{healthy: bool, reachable: bool, protocol: string} */
    public function healthCheck(): array
    {
        $timeout = max(1, (float) config('attachments.clamav.connect_timeout_seconds', 5));
        $address = config('attachments.clamav.socket')
            ? 'unix://'.config('attachments.clamav.socket')
            : 'tcp://'.config('attachments.clamav.host', '127.0.0.1').':'.(int) config('attachments.clamav.port', 3310);
        $socket = @stream_socket_client($address, $errorCode, $errorMessage, $timeout, STREAM_CLIENT_CONNECT);
        if (! is_resource($socket)) return ['healthy' => false, 'reachable' => false, 'protocol' => 'unavailable'];
        try {
            stream_set_timeout($socket, (int) $timeout);
            if (@fwrite($socket, "zPING\0") !== 6) return ['healthy' => false, 'reachable' => true, 'protocol' => 'write_failed'];
            $response = fread($socket, 64);
            $timedOut = stream_get_meta_data($socket)['timed_out'] ?? false;
            if ($timedOut) return ['healthy' => false, 'reachable' => true, 'protocol' => 'timeout'];
            $response = trim(str_replace("\0", '', (string) $response));
            return $response === 'PONG'
                ? ['healthy' => true, 'reachable' => true, 'protocol' => 'pong']
                : ['healthy' => false, 'reachable' => true, 'protocol' => 'malformed'];
        } finally {
            fclose($socket);
        }
    }

    public function scan(AttachmentScanRequest $request): AttachmentScanResultData
    {
        $started = microtime(true);
        $maxBytes = (int) config('attachments.max_bytes', 26214400);
        if ($request->sizeBytes < 0 || $request->sizeBytes > $maxBytes) {
            return new AttachmentScanResultData(AttachmentScanResult::Failed, scannerVersion: 'clamav:limit');
        }

        $stream = Storage::disk($request->storageDisk)->readStream($request->storagePath);
        if (! is_resource($stream)) return new AttachmentScanResultData(AttachmentScanResult::Failed, scannerVersion: 'clamav:missing');

        $socket = null;
        try {
            $timeout = max(1, (float) config('attachments.clamav.connect_timeout_seconds', config('attachments.clamav.timeout_seconds', 30)));
            $address = (string) config('attachments.clamav.socket', '');
            $address = $address !== '' ? 'unix://'.$address : 'tcp://'.config('attachments.clamav.host', '127.0.0.1').':'.(int) config('attachments.clamav.port', 3310);
            $socket = @stream_socket_client($address, $errorCode, $errorMessage, $timeout, STREAM_CLIENT_CONNECT);
            if (! is_resource($socket)) return new AttachmentScanResultData(AttachmentScanResult::Failed, scannerVersion: 'clamav:unavailable');
            $readTimeout = max(1, (float) config('attachments.clamav.read_timeout_seconds', config('attachments.clamav.timeout_seconds', 30)));
            stream_set_timeout($socket, (int) $readTimeout, (int) (($readTimeout - (int) $readTimeout) * 1000000));
            if (@fwrite($socket, "zINSTREAM\0") !== 10) return new AttachmentScanResultData(AttachmentScanResult::Failed, scannerVersion: 'clamav:protocol');

            $sent = 0;
            while (! feof($stream)) {
                $chunk = fread($stream, 8192);
                if ($chunk === false || $chunk === '') break;
                $sent += strlen($chunk);
                if ($sent > $maxBytes) return new AttachmentScanResultData(AttachmentScanResult::Failed, scannerVersion: 'clamav:limit');
                $header = pack('N', strlen($chunk));
                if (@fwrite($socket, $header.$chunk) !== 4 + strlen($chunk)) return new AttachmentScanResultData(AttachmentScanResult::Failed, scannerVersion: 'clamav:write');
            }
            if (@fwrite($socket, pack('N', 0)) !== 4) return new AttachmentScanResultData(AttachmentScanResult::Failed, scannerVersion: 'clamav:protocol');

            $response = '';
            while (! feof($socket)) {
                $part = fread($socket, 4096);
                if ($part === false) break;
                $response .= $part;
                if (str_contains($response, "\0") || str_contains($response, 'OK') || str_contains($response, 'FOUND') || str_contains($response, 'ERROR')) break;
            }
            $meta = stream_get_meta_data($socket);
            if (($meta['timed_out'] ?? false) === true) return new AttachmentScanResultData(AttachmentScanResult::Failed, scannerVersion: 'clamav:timeout');
            $response = trim(str_replace("\0", '', $response));
            if (str_ends_with($response, 'OK')) return new AttachmentScanResultData(AttachmentScanResult::Clean, scannerVersion: 'clamav');
            if (str_contains($response, 'FOUND')) {
                $signature = trim((string) preg_replace('/^.*:\s*(.*?)\s+FOUND.*$/s', '$1', $response));
                $signature = preg_replace('/[^A-Za-z0-9._:+ -]/', '', $signature) ?: 'unknown';
                return new AttachmentScanResultData(AttachmentScanResult::Infected, mb_substr($signature, 0, 120), 'clamav');
            }
            return new AttachmentScanResultData(AttachmentScanResult::Failed, scannerVersion: 'clamav:malformed');
        } catch (\Throwable) {
            return new AttachmentScanResultData(AttachmentScanResult::Failed, scannerVersion: 'clamav:error');
        } finally {
            fclose($stream);
            if (is_resource($socket)) fclose($socket);
        }
    }
}
