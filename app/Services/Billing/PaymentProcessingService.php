<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\DTOs\Billing\RecordPaymentTransactionData;
use App\DTOs\Billing\VerifiedProviderEvent;
use App\DTOs\Billing\WebhookRequestData;
use App\Enums\BillingActivationStatus;
use App\Enums\BillingOrderStatus;
use App\Enums\PaymentProviderEventStatus;
use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentTransactionType;
use App\Enums\ProviderPaymentStatus;
use App\Exceptions\Billing\PaymentVerificationException;
use App\Jobs\Affiliates\RecordAffiliateConversionJob;
use App\Jobs\Billing\ActivatePaidSubscriptionJob;
use App\Jobs\Billing\ProcessPaymentProviderEventJob;
use App\Models\BillingCheckoutSession;
use App\Models\BillingOrder;
use App\Models\PaymentProviderEvent;
use App\Models\PaymentTransaction;
use App\Services\Audit\AuditLogWriter;
use App\Services\Billing\Invoice\InvoiceService;
use App\Services\Billing\StateMachines\BillingOrderStateMachine;
use App\Services\Billing\StateMachines\PaymentProviderEventStateMachine;
use App\ValueObjects\Money;
use Illuminate\Support\Facades\DB;

final class PaymentProcessingService
{
    public function __construct(
        private readonly PaymentGatewayResolver $gatewayResolver,
        private readonly PaymentPayloadRedactor $redactor,
        private readonly BillingOrderStateMachine $orderStateMachine,
        private readonly PaymentProviderEventStateMachine $eventStateMachine,
        private readonly PaymentOrderMatcher $orderMatcher,
        private readonly PaymentSettlementService $settlements,
        private readonly InvoiceService $invoices,
        private readonly AuditLogWriter $audit,
    ) {}

    public function ingestWebhook(WebhookRequestData $request): PaymentProviderEvent
    {
        $gateway = $this->gatewayResolver->resolve($request->provider);
        $verified = $gateway->verifyWebhook($request);
        $payloadHash = $this->redactor->fingerprint($request->payload);
        $preExisting = PaymentProviderEvent::query()
            ->where('provider', $verified->provider)
            ->where('provider_event_id', $verified->providerEventId)
            ->first();
        if ($preExisting instanceof PaymentProviderEvent && ! hash_equals($preExisting->payload_hash, $payloadHash)) {
            $this->audit->write('billing.callback.payload_conflict', null, $preExisting, null, [
                'provider' => $verified->provider, 'provider_event_id' => $verified->providerEventId,
            ]);
            throw new PaymentVerificationException('Provider event payload conflict.');
        }

        $event = DB::transaction(function () use ($request, $verified, $payloadHash): PaymentProviderEvent {
            $existing = PaymentProviderEvent::query()
                ->where('provider', $verified->provider)
                ->where('provider_event_id', $verified->providerEventId)
                ->lockForUpdate()
                ->first();

            if ($existing instanceof PaymentProviderEvent) {
                if (! hash_equals($existing->payload_hash, $payloadHash)) {
                    $this->audit->write('billing.callback.payload_conflict', null, $existing, null, [
                        'provider' => $verified->provider, 'provider_event_id' => $verified->providerEventId,
                    ]);
                    throw new PaymentVerificationException('Provider event payload conflict.');
                }
                if ($existing->status === PaymentProviderEventStatus::Processed) {
                    $this->audit->write('billing.webhook.duplicate', null, $existing, null, [
                        'provider_event_id' => $verified->providerEventId,
                    ]);
                }

                return $existing;
            }

            return PaymentProviderEvent::query()->create([
                'provider' => $verified->provider,
                'provider_event_id' => $verified->providerEventId,
                'event_type' => $verified->eventType,
                'payload_hash' => $payloadHash,
                'status' => PaymentProviderEventStatus::Received,
                'received_at' => now(),
                'payload_redacted' => $this->redactor->redact(array_merge($request->payload, [
                    'billing_order_id' => $verified->billingOrderId,
                    'amount_minor' => $verified->amountMinor,
                    'currency' => $verified->currency,
                    'succeeded' => $verified->succeeded,
                    'provider_transaction_id' => $verified->providerTransactionId,
                    'failure_code' => $verified->failureCode,
                    'failure_message' => $verified->failureMessage,
                    'payment_status' => $verified->normalizedStatus()->value,
                    'transaction_type' => $verified->transactionType->value,
                    'provider_order_reference' => $verified->providerOrderReference,
                    'provider_session_id' => $verified->providerSessionId,
                    'settlement_status' => $verified->settlementStatus?->value,
                    'settlement_reference' => $verified->settlementReference,
                ])),
            ]);
        });

        ProcessPaymentProviderEventJob::dispatch((string) $event->getKey())
            ->afterCommit()
            ->onQueue((string) config('billing.queues.provider_events', 'default'));

        return $event;
    }

    public function processStoredEvent(PaymentProviderEvent $event, VerifiedProviderEvent $verified): void
    {
        if ($event->status === PaymentProviderEventStatus::Processed) {
            return;
        }

        try {
            DB::transaction(function () use ($event, $verified): void {
                $lockedEvent = PaymentProviderEvent::query()->whereKey($event->getKey())->lockForUpdate()->firstOrFail();
                if ($lockedEvent->status === PaymentProviderEventStatus::Processed) {
                    return;
                }
                $this->eventStateMachine->assertCanTransition($lockedEvent->status, PaymentProviderEventStatus::Processing);
                $lockedEvent->forceFill([
                    'status' => PaymentProviderEventStatus::Processing,
                    'attempts' => $lockedEvent->attempts + 1,
                ])->save();
                $this->processVerifiedPayment($verified, $lockedEvent);
                $lockedEvent->forceFill([
                    'status' => PaymentProviderEventStatus::Processed,
                    'processed_at' => now(),
                    'last_error' => null,
                ])->save();
            });
        } catch (\Throwable $exception) {
            DB::transaction(function () use ($event): void {
                $lockedEvent = PaymentProviderEvent::query()->whereKey($event->getKey())->lockForUpdate()->firstOrFail();
                if ($lockedEvent->status === PaymentProviderEventStatus::Processed) {
                    return;
                }
                $lockedEvent->forceFill([
                    'status' => PaymentProviderEventStatus::Failed,
                    'failed_at' => now(),
                    'attempts' => $lockedEvent->attempts + 1,
                    'last_error' => 'Payment provider event processing failed.',
                ])->save();
                $this->audit->write('billing.callback.processing_failed', null, $lockedEvent, null, [
                    'provider' => $lockedEvent->provider, 'internal_event_id' => $lockedEvent->getKey(),
                    'reason_code' => 'processing_failed',
                ]);
            });
            throw $exception;
        }
    }

    public function processVerifiedPayment(VerifiedProviderEvent $verified, ?PaymentProviderEvent $event = null): BillingOrder
    {
        if (! $verified->signatureVerified || $verified->normalizedStatus() === ProviderPaymentStatus::Unknown) {
            throw new PaymentVerificationException('Unverified or unknown payment event.');
        }

        return match ($verified->normalizedStatus()) {
            ProviderPaymentStatus::Authorized => $this->recordAuthorization($verified),
            ProviderPaymentStatus::Captured => $this->recordCapture($verified),
            ProviderPaymentStatus::Succeeded => $this->recordSuccessfulPayment($verified, $event),
            ProviderPaymentStatus::Pending, ProviderPaymentStatus::Initiated => $this->recordPendingPayment($verified),
            ProviderPaymentStatus::Cancelled => $this->recordTerminalAttempt($verified, BillingOrderStatus::Cancelled),
            ProviderPaymentStatus::Expired => $this->recordTerminalAttempt($verified, BillingOrderStatus::Expired),
            default => $this->recordFailedPayment($verified, $event),
        };
    }

    public function recordSuccessfulPayment(VerifiedProviderEvent $verified, ?PaymentProviderEvent $event = null): BillingOrder
    {
        if ($verified->transactionType === PaymentTransactionType::Authorization) {
            return $this->recordAuthorization($verified);
        }
        if ($verified->transactionType === PaymentTransactionType::Capture) {
            return $this->recordCapture($verified);
        }
        $order = $this->orderMatcher->match($verified);

        if ($order->status === BillingOrderStatus::Paid) {
            $this->invoices->issuePaidFromOrder($order);

            return $order;
        }

        if ($order->provider !== null && $order->provider !== $verified->provider) {
            throw new PaymentVerificationException('Provider mismatch.');
        }

        $expected = Money::fromMinor($order->total_minor, $order->currency);
        $received = Money::fromMinor($verified->amountMinor, $verified->currency);

        if ($expected->currency !== $received->currency) {
            $this->persistVerificationAudit('billing.payment.currency_mismatch', $order, [
                'expected_currency' => $expected->currency,
                'received_currency' => $received->currency,
            ]);
            throw new PaymentVerificationException('Payment currency mismatch.');
        }

        if ($expected->amountMinor !== $received->amountMinor) {
            $this->persistVerificationAudit('billing.payment.amount_mismatch', $order, [
                'expected_minor' => $expected->amountMinor,
                'received_minor' => $received->amountMinor,
            ]);
            throw new PaymentVerificationException('Payment amount mismatch.');
        }

        return DB::transaction(function () use ($verified, $order): BillingOrder {
            $locked = BillingOrder::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status === BillingOrderStatus::Paid) {
                return $locked;
            }

            $transaction = $this->appendTransaction(new RecordPaymentTransactionData(
                billingOrderId: (string) $locked->getKey(),
                userId: $locked->user_id,
                provider: $verified->provider,
                type: $verified->transactionType,
                status: PaymentTransactionStatus::Succeeded,
                amountMinor: $verified->amountMinor,
                currency: $verified->currency,
                providerTransactionId: $verified->providerTransactionId,
                idempotencyKey: 'success:'.$verified->providerEventId,
                providerEventId: $verified->providerEventId,
                processedAt: now(),
            ));

            if (in_array($locked->status, [BillingOrderStatus::Cancelled, BillingOrderStatus::Expired, BillingOrderStatus::Failed], true)) {
                $metadata = $locked->metadata ?? [];
                $metadata['reconciliation_reason'] = 'late_success_after_terminal_order';
                $locked->forceFill(['metadata' => $metadata])->save();
                $this->audit->write('billing.payment.reconciliation_required', $locked->user_id, $locked, null, [
                    'reason_code' => 'late_success_after_terminal_order',
                ]);

                return $locked->fresh();
            }

            if ($locked->status === BillingOrderStatus::Pending) {
                $this->orderStateMachine->assertCanTransition($locked->status, BillingOrderStatus::Processing);
                $locked->forceFill(['status' => BillingOrderStatus::Processing])->save();
            }

            $this->orderStateMachine->assertCanTransition($locked->status, BillingOrderStatus::Paid);
            $metadata = $locked->metadata ?? [];
            $metadata['activation_status'] = BillingActivationStatus::Pending->value;
            $locked->forceFill([
                'status' => BillingOrderStatus::Paid,
                'paid_at' => now(),
                'metadata' => $metadata,
            ])->save();
            $this->completeCheckoutSessions($locked);
            $this->recordSettlementIfPresent($verified, $transaction);
            $this->invoices->issuePaidFromOrder($locked->fresh() ?? $locked, $transaction);

            $this->audit->write('billing.payment.succeeded', $locked->user_id, $locked, null, [
                'provider_transaction_id' => $verified->providerTransactionId,
                'provider_event_id' => $verified->providerEventId,
            ]);

            ActivatePaidSubscriptionJob::dispatch((string) $locked->getKey())
                ->afterCommit()
                ->onQueue((string) config('billing.queues.activation', 'default'));

            if (config('affiliates.enabled') === true) {
                RecordAffiliateConversionJob::dispatch((string) $locked->getKey())
                    ->afterCommit()
                    ->onQueue((string) config('billing.queues.activation', 'default'));
            }

            return $locked->fresh();
        });
    }

    public function recordAuthorization(VerifiedProviderEvent $verified): BillingOrder
    {
        return DB::transaction(function () use ($verified): BillingOrder {
            $order = $this->orderMatcher->match($verified);
            $this->validateProviderAndCurrency($order, $verified);
            if ($verified->amountMinor < 1 || $verified->amountMinor > $order->total_minor) {
                throw new PaymentVerificationException('Authorization amount is invalid.');
            }
            $this->appendTransaction(new RecordPaymentTransactionData(
                billingOrderId: (string) $order->getKey(), userId: $order->user_id,
                provider: $verified->provider, type: PaymentTransactionType::Authorization,
                status: PaymentTransactionStatus::Succeeded, amountMinor: $verified->amountMinor,
                currency: $verified->currency, providerTransactionId: $verified->providerTransactionId,
                idempotencyKey: 'authorization:'.$verified->providerEventId,
                providerEventId: $verified->providerEventId, processedAt: now(),
                metadata: ['provider_status' => ProviderPaymentStatus::Authorized->value],
            ));
            if ($order->status === BillingOrderStatus::Pending) {
                $order->forceFill(['status' => BillingOrderStatus::Processing])->save();
            }
            $this->audit->write('billing.payment.authorized', $order->user_id, $order, null, [
                'provider' => $verified->provider, 'amount_minor' => $verified->amountMinor, 'currency' => $verified->currency,
            ]);

            return $order->fresh();
        });
    }

    public function recordCapture(VerifiedProviderEvent $verified): BillingOrder
    {
        return DB::transaction(function () use ($verified): BillingOrder {
            $order = $this->orderMatcher->match($verified);
            $this->validateProviderAndCurrency($order, $verified);
            $captured = (int) PaymentTransaction::query()->where('billing_order_id', $order->getKey())
                ->where('type', PaymentTransactionType::Capture)->where('status', PaymentTransactionStatus::Succeeded)
                ->sum('amount_minor');
            $authorized = (int) PaymentTransaction::query()->where('billing_order_id', $order->getKey())
                ->where('type', PaymentTransactionType::Authorization)->where('status', PaymentTransactionStatus::Succeeded)
                ->sum('amount_minor');
            if ($verified->amountMinor < 1 || $captured + $verified->amountMinor > $order->total_minor
                || ($authorized > 0 && $captured + $verified->amountMinor > $authorized)) {
                throw new PaymentVerificationException('Capture exceeds payable or authorized amount.');
            }
            $transaction = $this->appendTransaction(new RecordPaymentTransactionData(
                billingOrderId: (string) $order->getKey(), userId: $order->user_id,
                provider: $verified->provider, type: PaymentTransactionType::Capture,
                status: PaymentTransactionStatus::Succeeded, amountMinor: $verified->amountMinor,
                currency: $verified->currency, providerTransactionId: $verified->providerTransactionId,
                idempotencyKey: 'capture:'.$verified->providerEventId,
                providerEventId: $verified->providerEventId, processedAt: now(),
            ));
            $totalCaptured = $captured + $verified->amountMinor;
            $this->audit->write('billing.payment.captured', $order->user_id, $order, null, [
                'amount_minor' => $verified->amountMinor, 'captured_minor' => $totalCaptured,
            ]);
            if ($totalCaptured >= $order->total_minor) {
                return $this->markPaid($order, $verified, $transaction);
            }
            if ($order->status === BillingOrderStatus::Pending) {
                $order->forceFill(['status' => BillingOrderStatus::Processing])->save();
            }

            return $order->fresh();
        });
    }

    public function recordPendingPayment(VerifiedProviderEvent $verified): BillingOrder
    {
        return DB::transaction(function () use ($verified): BillingOrder {
            $order = $this->orderMatcher->match($verified);
            if ($order->status === BillingOrderStatus::Paid) {
                $this->audit->write('billing.payment.out_of_order_ignored', $order->user_id, $order, null, ['status' => 'pending']);

                return $order;
            }
            $this->appendTransaction(new RecordPaymentTransactionData(
                billingOrderId: (string) $order->getKey(), userId: $order->user_id,
                provider: $verified->provider, type: $verified->transactionType,
                status: PaymentTransactionStatus::Pending, amountMinor: max(0, $verified->amountMinor),
                currency: $verified->currency, providerTransactionId: $verified->providerTransactionId,
                idempotencyKey: 'pending:'.$verified->providerEventId, providerEventId: $verified->providerEventId,
            ));
            $this->audit->write('billing.payment.pending', $order->user_id, $order, null, ['provider' => $verified->provider]);

            return $order->fresh();
        });
    }

    private function recordTerminalAttempt(VerifiedProviderEvent $verified, BillingOrderStatus $status): BillingOrder
    {
        return DB::transaction(function () use ($verified, $status): BillingOrder {
            $order = $this->orderMatcher->match($verified);
            if ($order->status === BillingOrderStatus::Paid) {
                $this->audit->write('billing.payment.out_of_order_ignored', $order->user_id, $order, null, ['status' => $status->value]);

                return $order;
            }
            if (in_array($order->status, [BillingOrderStatus::Pending, BillingOrderStatus::Processing], true)) {
                $order->forceFill(['status' => $status])->save();
            }
            BillingCheckoutSession::query()->where('billing_order_id', $order->getKey())
                ->whereIn('status', ['created', 'pending', 'redirected'])->update(['status' => $status->value]);
            $this->audit->write('billing.payment.'.$status->value, $order->user_id, $order, null, ['provider' => $verified->provider]);

            return $order->fresh();
        });
    }

    /** @param  array<string, mixed>  $metadata */
    private function persistVerificationAudit(string $action, BillingOrder $order, array $metadata): void
    {
        DB::transaction(function () use ($action, $order, $metadata): void {
            $this->audit->write($action, $order->user_id, $order, null, $metadata);
        });
    }

    public function recordFailedPayment(VerifiedProviderEvent $verified, ?PaymentProviderEvent $event = null): BillingOrder
    {
        return DB::transaction(function () use ($verified): BillingOrder {
            $order = $this->orderMatcher->match($verified);

            $this->appendTransaction(new RecordPaymentTransactionData(
                billingOrderId: (string) $order->getKey(),
                userId: $order->user_id,
                provider: $verified->provider,
                type: $verified->transactionType,
                status: PaymentTransactionStatus::Failed,
                amountMinor: $verified->amountMinor,
                currency: $verified->currency,
                providerTransactionId: $verified->providerTransactionId,
                idempotencyKey: 'failed:'.$verified->providerEventId,
                providerEventId: $verified->providerEventId,
                failureCode: $verified->failureCode,
                failureMessage: $verified->failureMessage,
            ));

            if ($order->status !== BillingOrderStatus::Paid && in_array($order->status, [BillingOrderStatus::Pending, BillingOrderStatus::Processing], true)) {
                $this->orderStateMachine->assertCanTransition($order->status, BillingOrderStatus::Failed);
                $order->forceFill(['status' => BillingOrderStatus::Failed])->save();
            }

            $this->audit->write('billing.payment.failed', $order->user_id, $order, null, [
                'failure_code' => $verified->failureCode,
            ]);

            return $order->fresh();
        });
    }

    private function validateProviderAndCurrency(BillingOrder $order, VerifiedProviderEvent $verified): void
    {
        if ($order->provider !== null && $order->provider !== $verified->provider) {
            throw new PaymentVerificationException('Provider mismatch.');
        }
        if ($order->currency !== strtoupper($verified->currency)) {
            throw new PaymentVerificationException('Payment currency mismatch.');
        }
    }

    private function markPaid(BillingOrder $order, VerifiedProviderEvent $verified, PaymentTransaction $transaction): BillingOrder
    {
        if ($order->status === BillingOrderStatus::Paid) {
            return $order;
        }
        if (in_array($order->status, [BillingOrderStatus::Cancelled, BillingOrderStatus::Expired, BillingOrderStatus::Failed], true)) {
            $metadata = $order->metadata ?? [];
            $metadata['reconciliation_reason'] = 'late_capture_after_terminal_order';
            $order->forceFill(['metadata' => $metadata])->save();
            $this->audit->write('billing.payment.reconciliation_required', $order->user_id, $order, null, [
                'reason_code' => 'late_capture_after_terminal_order',
            ]);

            return $order->fresh();
        }
        if ($order->status === BillingOrderStatus::Pending) {
            $order->forceFill(['status' => BillingOrderStatus::Processing])->save();
        }
        $metadata = $order->metadata ?? [];
        $metadata['activation_status'] = BillingActivationStatus::Pending->value;
        $order->forceFill(['status' => BillingOrderStatus::Paid, 'paid_at' => now(), 'metadata' => $metadata])->save();
        $this->completeCheckoutSessions($order);
        $this->recordSettlementIfPresent($verified, $transaction);
        $this->invoices->issuePaidFromOrder($order->fresh() ?? $order, $transaction);
        ActivatePaidSubscriptionJob::dispatch((string) $order->getKey())->afterCommit()
            ->onQueue((string) config('billing.queues.activation', 'default'));
        $this->audit->write('billing.subscription.activation_dispatched', $order->user_id, $order);

        if (config('affiliates.enabled') === true) {
            RecordAffiliateConversionJob::dispatch((string) $order->getKey())
                ->afterCommit()
                ->onQueue((string) config('billing.queues.activation', 'default'));
        }

        return $order->fresh();
    }

    private function completeCheckoutSessions(BillingOrder $order): void
    {
        BillingCheckoutSession::query()->where('billing_order_id', $order->getKey())
            ->whereIn('status', ['created', 'pending', 'redirected'])->update(['status' => 'completed', 'updated_at' => now()]);
    }

    private function recordSettlementIfPresent(VerifiedProviderEvent $verified, PaymentTransaction $transaction): void
    {
        if ($verified->settlementStatus !== null) {
            $this->settlements->record(
                $transaction, $verified->settlementStatus, $verified->settlementReference,
                $verified->amountMinor, $verified->currency,
            );
        }
    }

    private function appendTransaction(RecordPaymentTransactionData $data): PaymentTransaction
    {
        $providerTransaction = PaymentTransaction::query()
            ->where('provider', $data->provider)
            ->where('provider_transaction_id', $data->providerTransactionId)
            ->first();
        if ($providerTransaction instanceof PaymentTransaction) {
            if ($providerTransaction->billing_order_id !== $data->billingOrderId
                || $providerTransaction->amount_minor !== $data->amountMinor
                || $providerTransaction->currency !== strtoupper($data->currency)
                || $providerTransaction->type !== $data->type) {
                throw new PaymentVerificationException('Conflicting provider transaction reference.');
            }
            if ($providerTransaction->status === PaymentTransactionStatus::Succeeded || $providerTransaction->status === $data->status) {
                return $providerTransaction;
            }
            if ($providerTransaction->status === PaymentTransactionStatus::Pending) {
                $providerTransaction->forceFill([
                    'status' => $data->status,
                    'provider_event_id' => $data->providerEventId,
                    'failure_code' => $data->failureCode,
                    'failure_message' => $data->failureMessage,
                    'processed_at' => $data->processedAt ?? now(),
                ])->save();

                return $providerTransaction->fresh();
            }
        }

        $existing = PaymentTransaction::query()
            ->where('billing_order_id', $data->billingOrderId)
            ->where('idempotency_key', $data->idempotencyKey)
            ->first();

        if ($existing instanceof PaymentTransaction) {
            return $existing;
        }

        return PaymentTransaction::query()->create([
            'billing_order_id' => $data->billingOrderId,
            'user_id' => $data->userId,
            'provider' => $data->provider,
            'type' => $data->type,
            'status' => $data->status,
            'amount_minor' => $data->amountMinor,
            'currency' => strtoupper($data->currency),
            'provider_transaction_id' => $data->providerTransactionId,
            'provider_event_id' => $data->providerEventId,
            'idempotency_key' => $data->idempotencyKey,
            'failure_code' => $data->failureCode,
            'failure_message' => $data->failureMessage,
            'processed_at' => $data->processedAt ?? ($data->status === PaymentTransactionStatus::Succeeded ? now() : null),
            'payload_fingerprint' => $data->payloadFingerprint,
            'metadata' => $data->metadata,
        ]);
    }
}
