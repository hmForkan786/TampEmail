<?php

use App\Enums\BillingOrderStatus;
use App\Enums\PaymentTransactionStatus;
use App\Exceptions\Billing\InvalidBillingStateTransitionException;
use App\Services\Billing\StateMachines\BillingOrderStateMachine;
use App\Services\Billing\StateMachines\PaymentTransactionStateMachine;

it('allows valid billing order transitions', function (): void {
    $machine = app(BillingOrderStateMachine::class);
    expect(fn () => $machine->assertCanTransition(BillingOrderStatus::Pending, BillingOrderStatus::Processing))->not->toThrow(Exception::class);
    expect(fn () => $machine->assertCanTransition(BillingOrderStatus::Paid, BillingOrderStatus::PartiallyRefunded))->not->toThrow(Exception::class);
});

it('rejects paid to pending and charged back to paid', function (): void {
    $machine = app(BillingOrderStateMachine::class);
    expect(fn () => $machine->assertCanTransition(BillingOrderStatus::Paid, BillingOrderStatus::Pending))
        ->toThrow(InvalidBillingStateTransitionException::class);
    expect(fn () => $machine->assertCanTransition(BillingOrderStatus::ChargedBack, BillingOrderStatus::Paid))
        ->toThrow(InvalidBillingStateTransitionException::class);
});

it('rejects failed transaction becoming succeeded without a new row contract', function (): void {
    $machine = app(PaymentTransactionStateMachine::class);
    expect(fn () => $machine->assertCanTransition(PaymentTransactionStatus::Failed, PaymentTransactionStatus::Succeeded))
        ->toThrow(InvalidBillingStateTransitionException::class);
});
