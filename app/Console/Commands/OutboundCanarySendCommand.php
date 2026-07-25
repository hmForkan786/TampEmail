<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Outbound\CreateOutboundSendAction;
use App\DTOs\Outbound\CreateOutboundSendData;
use App\Exceptions\OutboundSendException;
use App\Jobs\DeliverOutboundMessageJob;
use App\Models\Inbox;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Optional, explicit canary test send through the real outbound transport.
 *
 * Never runs automatically — guarded by `RUN_OUTBOUND_SMTP_TESTS=1` (mapped
 * to `outbound.launch.canary_send.enabled`), an admin actor, and an
 * approved recipient allow-list. Idempotent per hour/recipient/inbox,
 * rate limited, and never attaches a file by default. Reports transport
 * acceptance ("accepted"), never claims final delivery — that only comes
 * from a verified provider event.
 */
final class OutboundCanarySendCommand extends Command
{
    protected $signature = 'outbound:canary-send
        {--actor= : Platform-admin user ID authorizing this canary send}
        {--inbox= : Sending inbox ID}
        {--recipient= : Approved canary recipient email address}
        {--json : Print a JSON result}';

    protected $description = 'Send a single rate-limited, idempotent canary test email through the real outbound transport (admin-only, opt-in via RUN_OUTBOUND_SMTP_TESTS=1).';

    public function handle(CreateOutboundSendAction $createSend): int
    {
        if (! (bool) config('outbound.launch.canary_send.enabled', false)) {
            return $this->rejectWith('canary_send_disabled', 'Canary send is disabled. Set RUN_OUTBOUND_SMTP_TESTS=1 to run this command.');
        }

        $actor = $this->resolveActor();
        if ($actor === null) {
            return $this->rejectWith('actor_invalid', 'A --actor platform-admin user ID is required.');
        }

        $inbox = $this->resolveInbox();
        if ($inbox === null) {
            return $this->rejectWith('inbox_invalid', 'A valid --inbox ID is required.');
        }

        $recipient = strtolower(trim((string) $this->option('recipient')));
        $allowed = (array) config('outbound.launch.canary_send.allowed_recipients', []);
        if ($recipient === '' || $allowed === [] || ! in_array($recipient, $allowed, true)) {
            return $this->rejectWith('recipient_not_approved', 'Recipient is not in the approved canary-send allow-list.');
        }

        $rateLimitKey = 'outbound-canary-send';
        $maxPerHour = max(1, (int) config('outbound.launch.canary_send.rate_limit_per_hour', 3));
        if (RateLimiter::tooManyAttempts($rateLimitKey, $maxPerHour)) {
            return $this->rejectWith('rate_limited', 'Canary send is rate limited; try again later.');
        }

        $hourBucket = now()->format('Y-m-d-H');
        $idempotencyKey = 'canary-send:'.$hourBucket.':'.hash('sha256', $inbox->getKey().'|'.$recipient);
        $subject = trim(((string) config('outbound.launch.canary_send.subject_prefix', '[Outbound Canary Test]')).' '.$hourBucket);

        try {
            $message = $createSend->execute(
                new CreateOutboundSendData(
                    inboxId: (string) $inbox->getKey(),
                    idempotencyKey: $idempotencyKey,
                    to: [$recipient],
                    cc: [],
                    bcc: [],
                    subject: $subject,
                    textBody: 'This is an automated outbound launch canary test message. No action is required.',
                    htmlBody: null,
                    fromDisplayName: null,
                ),
                $actor,
                null,
            );
        } catch (OutboundSendException $exception) {
            return $this->rejectWith($exception->errorCode, 'Canary send was rejected: '.$exception->errorCode);
        }

        RateLimiter::hit($rateLimitKey, 3600);

        if (! $message->is_canary) {
            $message->forceFill(['is_canary' => true])->save();
        }

        app()->call([new DeliverOutboundMessageJob((string) $message->getKey()), 'handle']);

        $fresh = $message->fresh();
        $result = $fresh->state?->value === 'sent' ? 'accepted' : $fresh->state?->value;

        $payload = [
            'result' => $result,
            'message_id' => (string) $fresh->getKey(),
            'state' => $fresh->state?->value,
            'failure_code' => $fresh->failure_code,
        ];

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            $this->line('result: '.$payload['result']);
            $this->line('message_id: '.$payload['message_id']);
        }

        return $result === 'accepted' ? self::SUCCESS : self::FAILURE;
    }

    private function resolveActor(): ?User
    {
        $actorId = trim((string) $this->option('actor'));
        if ($actorId === '') {
            return null;
        }

        $actor = User::query()->find($actorId);
        if ($actor === null || ! $actor->isPlatformAdmin()) {
            return null;
        }

        return $actor;
    }

    private function resolveInbox(): ?Inbox
    {
        $inboxId = trim((string) $this->option('inbox'));
        if ($inboxId === '') {
            return null;
        }

        return Inbox::query()->with('domain')->find($inboxId);
    }

    private function rejectWith(string $code, string $message): int
    {
        $payload = ['result' => 'rejected', 'failure_code' => $code];

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            $this->components->error($message);
        }

        return self::FAILURE;
    }
}
