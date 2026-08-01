<?php

use App\Enums\PaymentTransactionStatus;
use App\Exceptions\Billing\DisabledPaymentProviderException;
use App\Exceptions\Billing\InvalidBillingStateTransitionException;
use App\Exceptions\Billing\UnknownPaymentProviderException;
use App\Services\Billing\PaymentGatewayRegistry;
use App\Services\Billing\PaymentGatewayResolver;
use App\Services\Billing\StateMachines\PaymentTransactionStateMachine;

it('resolves the fake gateway from config', function (): void {
    $gateway = app(PaymentGatewayResolver::class)->resolve('fake');
    expect($gateway->name())->toBe('fake');
});

it('fail-closes unknown providers', function (): void {
    expect(fn () => app(PaymentGatewayResolver::class)->resolve('not-a-real-gateway'))
        ->toThrow(UnknownPaymentProviderException::class);
});

it('fail-closes disabled providers', function (): void {
    config(['billing.enabled_gateways' => []]);
    expect(fn () => app(PaymentGatewayResolver::class)->resolve('fake'))
        ->toThrow(DisabledPaymentProviderException::class);
});

it('lists registered providers from the registry', function (): void {
    expect(app(PaymentGatewayRegistry::class)->registeredProviders())->toContain('fake');
});

it('rejects invalid transaction resurrection at the state machine layer', function (): void {
    $machine = app(PaymentTransactionStateMachine::class);
    expect(fn () => $machine->assertCanTransition(PaymentTransactionStatus::Cancelled, PaymentTransactionStatus::Succeeded))
        ->toThrow(InvalidBillingStateTransitionException::class);
});
