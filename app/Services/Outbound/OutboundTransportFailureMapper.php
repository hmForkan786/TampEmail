<?php

declare(strict_types=1);

namespace App\Services\Outbound;

use App\DTOs\Outbound\OutboundDeliveryResult;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Throwable;

/**
 * Maps mailer/provider exceptions onto sanitized outbound transport results.
 *
 * Never persists raw SMTP responses or credentials.
 */
final class OutboundTransportFailureMapper
{
    public function map(Throwable $exception, string $provider): OutboundDeliveryResult
    {
        $message = mb_strtolower($exception->getMessage());
        $message = $this->redactSecrets($message);

        if ($this->isConfigurationFailure($message, $exception)) {
            return OutboundDeliveryResult::configurationFailure(
                failureCode: $this->configurationCode($message),
                failureMessage: 'Outbound transport configuration is invalid.',
                provider: $provider,
            );
        }

        if ($this->isTemporaryFailure($message, $exception)) {
            return OutboundDeliveryResult::temporaryFailure(
                failureCode: $this->temporaryCode($message),
                failureMessage: 'Temporary transport failure.',
                provider: $provider,
            );
        }

        if ($this->isPermanentFailure($message)) {
            return OutboundDeliveryResult::permanentFailure(
                failureCode: $this->permanentCode($message),
                failureMessage: 'Transport submission was rejected.',
                provider: $provider,
            );
        }

        return OutboundDeliveryResult::permanentFailure(
            failureCode: 'transport_error',
            failureMessage: 'Transport submission failed.',
            provider: $provider,
        );
    }

    private function isConfigurationFailure(string $message, Throwable $exception): bool
    {
        return str_contains($message, 'authentication failed')
            || str_contains($message, 'auth failed')
            || str_contains($message, 'invalid credentials')
            || str_contains($message, 'username and password not accepted')
            || str_contains($message, 'must issue a starttls')
            || str_contains($message, 'certificate')
            || (str_contains($message, 'ssl') && str_contains($message, 'verify'))
            || str_contains($message, 'unable to connect with tls')
            || (! $exception instanceof TransportExceptionInterface && str_contains($message, 'mailer'));
    }

    private function isTemporaryFailure(string $message, Throwable $exception): bool
    {
        if (preg_match('/\b(421|450|451|452)\b/', $message) === 1) {
            return true;
        }

        $needles = [
            'timed out',
            'timeout',
            'connection refused',
            'connection reset',
            'could not connect',
            'temporary',
            'temporarily',
            'try again',
            'rate limit',
            'too many',
            'dns',
            'getaddrinfo',
            'name or service not known',
            'network is unreachable',
            'broken pipe',
        ];

        foreach ($needles as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return $exception instanceof TransportExceptionInterface
            && preg_match('/\b4\d{2}\b/', $message) === 1;
    }

    private function isPermanentFailure(string $message): bool
    {
        if (preg_match('/\b5\d{2}\b/', $message) === 1) {
            return true;
        }

        $needles = [
            'invalid recipient',
            'user unknown',
            'mailbox unavailable',
            'relay access denied',
            'sender address rejected',
            'message size exceeds',
            'mime',
            'malformed',
            'unauthorized',
            'forbidden',
        ];

        foreach ($needles as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function configurationCode(string $message): string
    {
        if (str_contains($message, 'auth') || str_contains($message, 'credential') || str_contains($message, 'password')) {
            return 'credentials_rejected';
        }
        if (str_contains($message, 'tls') || str_contains($message, 'ssl') || str_contains($message, 'certificate')) {
            return 'tls_configuration';
        }

        return 'invalid_config';
    }

    private function temporaryCode(string $message): string
    {
        if (preg_match('/\b(421|450|451|452)\b/', $message, $matches) === 1) {
            return 'smtp_'.$matches[1];
        }
        if (str_contains($message, 'rate') || str_contains($message, 'too many')) {
            return 'rate_limit';
        }
        if (str_contains($message, 'timeout') || str_contains($message, 'timed out')) {
            return 'timeout';
        }
        if (str_contains($message, 'dns') || str_contains($message, 'getaddrinfo') || str_contains($message, 'name or service')) {
            return 'dns_failure';
        }

        return 'transport_temporary';
    }

    private function permanentCode(string $message): string
    {
        if (preg_match('/\b(5\d{2})\b/', $message, $matches) === 1) {
            return 'smtp_'.$matches[1];
        }
        if (str_contains($message, 'recipient') || str_contains($message, 'user unknown') || str_contains($message, 'mailbox')) {
            return 'invalid_recipient';
        }
        if (str_contains($message, 'size')) {
            return 'message_too_large';
        }

        return 'transport_rejected';
    }

    private function redactSecrets(string $message): string
    {
        $message = preg_replace('/password[=:]\s*\S+/i', 'password=[redacted]', $message) ?? $message;
        $message = preg_replace('/\b(AUTH\s+\w+\s+)\S+/i', '$1[redacted]', $message) ?? $message;

        return $message;
    }
}
