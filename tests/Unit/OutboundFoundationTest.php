<?php

declare(strict_types=1);

use App\Contracts\OutboundTransportInterface;
use App\DTOs\Outbound\OutboundDeliveryResult;
use App\DTOs\Outbound\OutboundMessageData;
use App\Enums\OutboundMessageState;
use App\Enums\OutboundOperation;
use App\Enums\OutboundTransportResult;
use App\Services\Outbound\UnavailableOutboundTransport;
use Tests\TestCase;

uses(TestCase::class);

it('defines outbound message state values and transitions', function (): void {
    expect(array_map(fn (OutboundMessageState $s) => $s->value, OutboundMessageState::cases()))->toBe([
        'draft',
        'queued',
        'sending',
        'sent',
        'delivered',
        'failed',
        'cancelled',
    ]);

    expect(OutboundMessageState::Draft->canTransitionTo(OutboundMessageState::Queued))->toBeTrue()
        ->and(OutboundMessageState::Queued->canTransitionTo(OutboundMessageState::Sending))->toBeTrue()
        ->and(OutboundMessageState::Queued->canTransitionTo(OutboundMessageState::Cancelled))->toBeTrue()
        ->and(OutboundMessageState::Sending->canTransitionTo(OutboundMessageState::Sent))->toBeTrue()
        ->and(OutboundMessageState::Sending->canTransitionTo(OutboundMessageState::Failed))->toBeTrue()
        ->and(OutboundMessageState::Sending->canTransitionTo(OutboundMessageState::Queued))->toBeTrue()
        ->and(OutboundMessageState::Failed->canTransitionTo(OutboundMessageState::Queued))->toBeTrue()
        ->and(OutboundMessageState::Sent->canTransitionTo(OutboundMessageState::Failed))->toBeFalse()
        ->and(OutboundMessageState::Sent->isTerminal())->toBeTrue()
        ->and(OutboundMessageState::Failed->blocksStaleJobMutation())->toBeTrue()
        ->and(OutboundMessageState::Queued->blocksStaleJobMutation())->toBeFalse();
});

it('defines outbound operations and feature keys', function (): void {
    expect(OutboundOperation::Send->featureKey())->toBe('send_email')
        ->and(OutboundOperation::Reply->featureKey())->toBe('reply_email')
        ->and(OutboundOperation::Forward->featureKey())->toBe('forward_email')
        ->and(OutboundOperation::Send->requiresSourceEmail())->toBeFalse()
        ->and(OutboundOperation::Reply->requiresSourceEmail())->toBeTrue()
        ->and(OutboundOperation::Forward->requiresSourceEmail())->toBeTrue();
});

it('maps transport results to message states', function (): void {
    expect(OutboundTransportResult::Accepted->toMessageState())->toBe(OutboundMessageState::Sent)
        ->and(OutboundTransportResult::Rejected->toMessageState())->toBe(OutboundMessageState::Failed)
        ->and(OutboundTransportResult::PermanentFailure->toMessageState())->toBe(OutboundMessageState::Failed)
        ->and(OutboundTransportResult::TemporaryFailure->toMessageState(scheduleRetry: true))->toBe(OutboundMessageState::Queued)
        ->and(OutboundTransportResult::TemporaryFailure->toMessageState(scheduleRetry: false))->toBe(OutboundMessageState::Failed)
        ->and(OutboundTransportResult::TemporaryFailure->isRetryable())->toBeTrue()
        ->and(OutboundTransportResult::Accepted->isSuccess())->toBeTrue();
});

it('serializes outbound message and delivery result DTOs', function (): void {
    $message = new OutboundMessageData(
        messageId: 'msg-1',
        fromAddress: 'inbox@example.test',
        fromDisplayName: 'Inbox',
        to: ['a@example.test'],
        cc: [],
        bcc: ['secret@example.test'],
        subject: 'Hello',
        textBody: 'Body',
        htmlBody: '<p>Body</p>',
    );

    expect($message->toArray())->toMatchArray([
        'message_id' => 'msg-1',
        'from_address' => 'inbox@example.test',
        'subject' => 'Hello',
        'bcc' => ['secret@example.test'],
    ]);

    $accepted = OutboundDeliveryResult::accepted('smtp', 'provider-123');
    expect($accepted->toArray())->toBe([
        'result' => 'accepted',
        'provider' => 'smtp',
        'provider_message_id' => 'provider-123',
        'failure_code' => null,
        'failure_message' => null,
    ]);

    $roundTrip = OutboundDeliveryResult::fromArray($accepted->toArray());
    expect($roundTrip->result)->toBe(OutboundTransportResult::Accepted)
        ->and($roundTrip->providerMessageId)->toBe('provider-123');
});

it('rejects invalid delivery result combinations', function (): void {
    expect(fn () => new OutboundDeliveryResult(
        result: OutboundTransportResult::Accepted,
        failureCode: 'nope',
    ))->toThrow(InvalidArgumentException::class);

    expect(fn () => new OutboundDeliveryResult(
        result: OutboundTransportResult::Rejected,
    ))->toThrow(InvalidArgumentException::class);

    expect(fn () => OutboundDeliveryResult::fromArray(['result' => 'not-a-result']))
        ->toThrow(InvalidArgumentException::class);
});

it('sanitizes failure codes on delivery results', function (): void {
    $result = OutboundDeliveryResult::temporaryFailure("smtp 4xx!\n<script>", "Temp\x00 failure");

    expect($result->failureCode)->toBe('smtp4xxscript')
        ->and($result->failureMessage)->toBe('Temp failure');
});

it('binds unavailable outbound transport by default', function (): void {
    $transport = app(OutboundTransportInterface::class);

    expect($transport)->toBeInstanceOf(UnavailableOutboundTransport::class);

    $result = $transport->send(new OutboundMessageData(
        messageId: 'msg-2',
        fromAddress: 'a@example.test',
        fromDisplayName: null,
        to: ['b@example.test'],
        cc: [],
        bcc: [],
        subject: 'Test',
        textBody: 'Hi',
        htmlBody: null,
    ));

    expect($result->result)->toBe(OutboundTransportResult::PermanentFailure)
        ->and($result->failureCode)->toBe('transport_unavailable')
        ->and($result->provider)->toBe('unavailable');
});
