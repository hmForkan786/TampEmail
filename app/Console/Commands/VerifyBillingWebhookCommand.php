<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\DTOs\Billing\RawWebhookRequest;
use App\Services\Billing\Webhook\ProviderWebhookVerificationService;
use DateTimeImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

final class VerifyBillingWebhookCommand extends Command
{
    protected $signature = 'billing:webhook-verify {--provider=} {--payload-file=} {--signature=} {--timestamp=} {--nonce=}';

    protected $description = 'Safely verify a configured provider webhook fixture';

    public function handle(ProviderWebhookVerificationService $verification): int
    {
        if (app()->environment('production') && ! config('billing.webhook_security.debug_command_enabled', false)) {
            $this->error('Webhook verification diagnostics are disabled in production.');

            return self::FAILURE;
        }
        $file = (string) $this->option('payload-file');
        $body = $file !== '' && is_file($file) ? file_get_contents($file) : false;
        if ($body === false) {
            $this->error('A readable payload file is required.');

            return self::INVALID;
        }
        $request = new RawWebhookRequest(
            strtolower((string) $this->option('provider')), 'POST', '/diagnostic', '', $body,
            ['x-fake-signature' => [(string) $this->option('signature')], 'x-fake-timestamp' => [(string) $this->option('timestamp')], 'x-fake-nonce' => [(string) $this->option('nonce')]],
            'application/json', strlen($body), null, new DateTimeImmutable('now'), (string) Str::uuid(),
        );
        $result = $verification->verify($request);
        $this->{$result->verified ? 'info' : 'error'}($result->verified ? 'PASS' : 'FAIL');

        return $result->verified ? self::SUCCESS : self::FAILURE;
    }
}
