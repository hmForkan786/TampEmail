<?php

use App\Enums\PaymentTransactionStatus;
use App\Exceptions\Billing\InvalidBillingStateTransitionException;
use App\Services\Billing\StateMachines\PaymentTransactionStateMachine;

it('allows pending to succeeded and succeeded to reversed', function (): void {
    $machine = app(PaymentTransactionStateMachine::class);
    expect(fn () => $machine->assertCanTransition(PaymentTransactionStatus::Pending, PaymentTransactionStatus::Succeeded))->not->toThrow(Exception::class);
    expect(fn () => $machine->assertCanTransition(PaymentTransactionStatus::Succeeded, PaymentTransactionStatus::Reversed))->not->toThrow(Exception::class);
});

it('rejects cancelled to succeeded transitions', function (): void {
    expect(fn () => app(PaymentTransactionStateMachine::class)->assertCanTransition(
        PaymentTransactionStatus::Cancelled,
        PaymentTransactionStatus::Succeeded,
    ))->toThrow(InvalidBillingStateTransitionException::class);
});
