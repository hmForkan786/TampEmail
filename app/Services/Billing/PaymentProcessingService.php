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
use App\Exceptions\Billing\BillingOrderNotFoundException;
use App\Exceptions\Billing\PaymentVerificationException;
use App\Jobs\Billing\ActivatePaidSubscriptionJob;
use App\Jobs\Billing\ProcessPaymentProviderEventJob;
use App\Models\BillingOrder;
use App\Models\PaymentProviderEvent;
use App\Models\PaymentTransaction;
use App\Services\Audit\AuditLogWriter;
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
        private readonly AuditLogWriter $audit,
    ) {}

    public function ingestWebhook(WebhookRequestData $request): PaymentProviderEvent
    {
        $gateway = $this->gatewayResolver->resolve($request->provider);
        $verified = $gateway->verifyWebhook($request);
        $payloadHash = $this->redactor->fingerprint($request->payload);

        $event = DB::transaction(function () use ($request, $verified, $payloadHash): PaymentProviderEvent {
            $existing = PaymentProviderEvent::query()
                ->where('provider', $verified->provider)
                ->where('provider_event_id', $verified->providerEventId)
                ->lockForUpdate()
                ->first();

            if ($existing instanceof PaymentProviderEvent) {
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

            try {
                if ($verified->succeeded) {
                    $this->recordSuccessfulPayment($verified, $lockedEvent);
                } else {
                    $this->recordFailedPayment($verified, $lockedEvent);
                }

                $lockedEvent->forceFill([
                    'status' => PaymentProviderEventStatus::Processed,
                    'processed_at' => now(),
                    'last_error' => null,
                ])->save();
            } catch (\Throwable $exception) {
                $lockedEvent->forceFill([
                    'status' => PaymentProviderEventStatus::Failed,
                    'failed_at' => now(),
                    'last_error' => $exception->getMessage(),
                ])->save();

                throw $exception;
            }
        });
    }

    public function recordSuccessfulPayment(VerifiedProviderEvent $verified, ?PaymentProviderEvent $event = null): BillingOrder
    {
        $order = BillingOrder::query()->whereKey($verified->billingOrderId)->lockForUpdate()->first();
        if ($order === null) {
            throw new BillingOrderNotFoundException('Billing order not found for provider event.');
        }

        if ($order->status === BillingOrderStatus::Paid) {
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

            $this->appendTransaction(new RecordPaymentTransactionData(
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

            $this->audit->write('billing.payment.succeeded', $locked->user_id, $locked, null, [
                'provider_transaction_id' => $verified->providerTransactionId,
                'provider_event_id' => $verified->providerEventId,
            ]);

            ActivatePaidSubscriptionJob::dispatch((string) $locked->getKey())
                ->afterCommit()
                ->onQueue((string) config('billing.queues.activation', 'default'));

            return $locked->fresh();
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
            $order = BillingOrder::query()->whereKey($verified->billingOrderId)->lockForUpdate()->firstOrFail();

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

            if ($order->status !== BillingOrderStatus::Paid) {
                $this->orderStateMachine->assertCanTransition($order->status, BillingOrderStatus::Failed);
                $order->forceFill(['status' => BillingOrderStatus::Failed])->save();
            }

            $this->audit->write('billing.payment.failed', $order->user_id, $order, null, [
                'failure_code' => $verified->failureCode,
            ]);

            return $order->fresh();
        });
    }

    private function appendTransaction(RecordPaymentTransactionData $data): PaymentTransaction
    {
        $existing = PaymentTransaction::query()
            ->where('billing_order_id', $data->billingOrderId)
            ->where('idempotency_key', $data->idempotencyKey)
            ->first();

        if ($existing instanceof PaymentTransaction) {
            return $existing;
        }

        $duplicate = PaymentTransaction::query()
            ->where('provider', $data->provider)
            ->where('provider_transaction_id', $data->providerTransactionId)
            ->where('status', PaymentTransactionStatus::Succeeded)
            ->first();

        if ($duplicate instanceof PaymentTransaction && $duplicate->billing_order_id !== $data->billingOrderId) {
            throw new PaymentVerificationException('Conflicting provider transaction reference.');
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
