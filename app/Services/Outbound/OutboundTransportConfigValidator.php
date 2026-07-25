<?php

declare(strict_types=1);

namespace App\Services\Outbound;

/**
 * Validates outbound transport configuration without sending mail.
 *
 * Never returns passwords or other secrets in the result payload.
 */
final class OutboundTransportConfigValidator
{
    private const ALLOWED_ENCRYPTION = ['tls', 'ssl', 'starttls', 'null', 'none', ''];

    /**
     * @return array{
     *     valid: bool,
     *     transport: string,
     *     mailer: string|null,
     *     failure_code: string|null,
     *     checks: array<string, bool|string|null>
     * }
     */
    public function validate(?string $transport = null): array
    {
        $transport = strtolower(trim((string) ($transport ?? config('outbound.transport', 'unavailable'))));
        $mailer = trim((string) config('outbound.mailer', 'outbound'));

        if ($transport === 'unavailable') {
            return $this->result(false, $transport, null, 'transport_unavailable', [
                'transport_selected' => true,
                'mailer_exists' => null,
            ]);
        }

        if (! in_array($transport, ['smtp', 'mail', 'array'], true)) {
            return $this->result(false, $transport, $mailer, 'invalid_transport', [
                'transport_selected' => false,
            ]);
        }

        if ($transport === 'array') {
            $mailers = array_keys(config('mail.mailers', []));
            $exists = in_array('array', $mailers, true);

            return $this->result($exists, $transport, 'array', $exists ? null : 'invalid_mailer', [
                'transport_selected' => true,
                'mailer_exists' => $exists,
            ]);
        }

        if ($mailer === '' || ! in_array($mailer, array_keys(config('mail.mailers', [])), true)) {
            return $this->result(false, $transport, $mailer === '' ? null : $mailer, 'invalid_mailer', [
                'transport_selected' => true,
                'mailer_exists' => false,
            ]);
        }

        // Production mail paths must never silently resolve to local/dev transports.
        $mailerTransport = (string) config("mail.mailers.{$mailer}.transport", '');
        if (in_array($mailerTransport, ['log', 'sendmail'], true) && app()->environment('production')) {
            return $this->result(false, $transport, $mailer, 'unsafe_mailer', [
                'transport_selected' => true,
                'mailer_exists' => true,
                'mailer_transport' => $mailerTransport,
            ]);
        }

        if ($mailerTransport === 'array') {
            return $this->result(true, $transport, $mailer, null, [
                'transport_selected' => true,
                'mailer_exists' => true,
                'mailer_transport' => 'array',
            ]);
        }

        if ($mailerTransport !== 'smtp' && $mailer !== 'outbound') {
            // Non-SMTP cloud mailers (ses/postmark/resend) are allowed when explicitly named.
            if (in_array($mailerTransport, ['ses', 'ses-v2', 'postmark', 'resend'], true)) {
                return $this->result(true, $transport, $mailer, null, [
                    'transport_selected' => true,
                    'mailer_exists' => true,
                    'mailer_transport' => $mailerTransport,
                ]);
            }
        }

        return $this->validateSmtpSettings($transport, $mailer);
    }

    /**
     * @return array{
     *     valid: bool,
     *     transport: string,
     *     mailer: string|null,
     *     failure_code: string|null,
     *     checks: array<string, bool|string|null>
     * }
     */
    private function validateSmtpSettings(string $transport, string $mailer): array
    {
        $host = trim((string) (
            $mailer === 'outbound'
                ? config('outbound.smtp.host')
                : (config("mail.mailers.{$mailer}.host") ?? config('outbound.smtp.host'))
        ));
        $port = $mailer === 'outbound'
            ? config('outbound.smtp.port')
            : (config("mail.mailers.{$mailer}.port") ?? config('outbound.smtp.port'));
        $encryption = strtolower(trim((string) (
            $mailer === 'outbound'
                ? config('outbound.smtp.encryption')
                : (config('outbound.smtp.encryption') ?? '')
        )));
        $username = (string) (
            $mailer === 'outbound'
                ? config('outbound.smtp.username')
                : (config("mail.mailers.{$mailer}.username") ?? config('outbound.smtp.username'))
        );
        $password = (string) (
            $mailer === 'outbound'
                ? config('outbound.smtp.password')
                : (config("mail.mailers.{$mailer}.password") ?? config('outbound.smtp.password'))
        );
        $timeout = $mailer === 'outbound'
            ? config('outbound.smtp.timeout')
            : (config("mail.mailers.{$mailer}.timeout") ?? config('outbound.smtp.timeout'));
        $requireAuth = (bool) config('outbound.smtp.require_auth', true);

        $checks = [
            'transport_selected' => true,
            'mailer_exists' => true,
            'host_present' => $host !== '',
            'host_valid' => $host !== '' && $this->isValidHost($host),
            'port_valid' => $this->isValidPort($port),
            'encryption_valid' => $this->isValidEncryption($encryption),
            'timeout_valid' => $this->isValidTimeout($timeout),
            'credentials_present' => ! $requireAuth || ($username !== '' && $password !== ''),
            'verify_peer' => (bool) config('outbound.smtp.verify_peer', true),
        ];

        if (! $checks['host_present'] || ! $checks['host_valid']) {
            return $this->result(false, $transport, $mailer, 'missing_host', $checks);
        }
        if (! $checks['port_valid']) {
            return $this->result(false, $transport, $mailer, 'invalid_port', $checks);
        }
        if (! $checks['encryption_valid']) {
            return $this->result(false, $transport, $mailer, 'invalid_encryption', $checks);
        }
        if (! $checks['timeout_valid']) {
            return $this->result(false, $transport, $mailer, 'invalid_timeout', $checks);
        }
        if (! $checks['credentials_present']) {
            return $this->result(false, $transport, $mailer, 'missing_credentials', $checks);
        }

        return $this->result(true, $transport, $mailer, null, $checks);
    }

    private function isValidHost(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return true;
        }

        return (bool) preg_match('/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*\.?$/i', $host);
    }

    private function isValidPort(mixed $port): bool
    {
        if ($port === null || $port === '') {
            return false;
        }
        if (! is_numeric($port)) {
            return false;
        }
        $value = (int) $port;

        return $value >= 1 && $value <= 65535;
    }

    private function isValidEncryption(string $encryption): bool
    {
        return in_array($encryption, self::ALLOWED_ENCRYPTION, true);
    }

    private function isValidTimeout(mixed $timeout): bool
    {
        if ($timeout === null || $timeout === '') {
            return true;
        }
        if (! is_numeric($timeout)) {
            return false;
        }
        $value = (int) $timeout;

        return $value >= 1 && $value <= 300;
    }

    /**
     * @param  array<string, bool|string|null>  $checks
     * @return array{
     *     valid: bool,
     *     transport: string,
     *     mailer: string|null,
     *     failure_code: string|null,
     *     checks: array<string, bool|string|null>
     * }
     */
    private function result(bool $valid, string $transport, ?string $mailer, ?string $failureCode, array $checks): array
    {
        return [
            'valid' => $valid,
            'transport' => $transport,
            'mailer' => $mailer,
            'failure_code' => $failureCode,
            'checks' => $checks,
        ];
    }
}
