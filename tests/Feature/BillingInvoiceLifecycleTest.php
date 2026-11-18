<?php

use App\DTOs\Billing\CreateBillingOrderData;
use App\Enums\BillingCycle;
use App\Enums\BillingOrderStatus;
use App\Enums\BillingOrderType;
use App\Enums\CreditNoteStatus;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentTransactionType;
use App\Exceptions\Billing\InvoiceException;
use App\Models\AuditLog;
use App\Models\BillingInvoice;
use App\Models\BillingOrder;
use App\Models\PaymentTransaction;
use App\Services\Billing\BillingOrderService;
use App\Services\Billing\Invoice\CreditNoteService;
use App\Services\Billing\Invoice\InvoiceNumberAllocator;
use App\Services\Billing\Invoice\InvoicePdfService;
use App\Services\Billing\Invoice\InvoiceService;
use App\Services\Billing\PaymentProcessingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('api.key_hash_secret', 'billing-invoice-test-secret');
});

require_once __DIR__.'/../Support/billing_helpers.php';
require_once __DIR__.'/../Support/commercial_api_helpers.php';

it('generates a paid invoice from a verified purchase payment', function (): void {
    Queue::fake();
    [$user, $plan] = billingPremiumContext();
    $order = app(BillingOrderService::class)->create(billingOrderData($user, $plan, 'inv-purchase'));

    app(PaymentProcessingService::class)->recordSuccessfulPayment(
        verifiedFromOrder($order, eventId: 'evt-inv-purchase'),
    );

    $invoice = BillingInvoice::query()->where('billing_order_id', $order->getKey())->first();
    expect($invoice)->not->toBeNull()
        ->and($invoice->status)->toBe(InvoiceStatus::Paid)
        ->and($invoice->invoice_number)->toStartWith('INV-'.now()->format('Y').'-')
        ->and($invoice->total_minor)->toBe($order->total_minor)
        ->and($invoice->lineItems)->toHaveCount(1)
        ->and(AuditLog::query()->where('action', 'invoice_paid')->count())->toBe(1);
});

it('generates renewal and recovery invoices', function (): void {
    Queue::fake();
    [$user, $plan] = billingPremiumContext();

    $renewal = app(BillingOrderService::class)->create(new CreateBillingOrderData(
        userId: (string) $user->getKey(),
        planId: (string) $plan->getKey(),
        type: BillingOrderType::Renewal,
        billingCycle: BillingCycle::Monthly,
        idempotencyKey: 'inv-renewal',
    ));
    app(PaymentProcessingService::class)->recordSuccessfulPayment(verifiedFromOrder($renewal, eventId: 'evt-renewal'));

    $recovery = app(BillingOrderService::class)->create(new CreateBillingOrderData(
        userId: (string) $user->getKey(),
        planId: (string) $plan->getKey(),
        type: BillingOrderType::Renewal,
        billingCycle: BillingCycle::Monthly,
        idempotencyKey: 'inv-recovery',
    ));
    $recovery->forceFill(['metadata' => array_merge($recovery->metadata ?? [], ['recovery' => true])])->save();
    app(PaymentProcessingService::class)->recordSuccessfulPayment(verifiedFromOrder($recovery, eventId: 'evt-recovery'));

    $renewalInvoice = BillingInvoice::query()->where('billing_order_id', $renewal->getKey())->firstOrFail();
    $recoveryInvoice = BillingInvoice::query()->where('billing_order_id', $recovery->getKey())->firstOrFail();

    expect($renewalInvoice->status)->toBe(InvoiceStatus::Paid)
        ->and(($renewalInvoice->metadata ?? [])['order_type'])->toBe('renewal')
        ->and(($recoveryInvoice->metadata ?? [])['recovery'])->toBeTrue();
});

it('prevents duplicate invoices for the same order', function (): void {
    Queue::fake();
    [$user, $plan] = billingPremiumContext();
    $order = app(BillingOrderService::class)->create(billingOrderData($user, $plan, 'inv-dup'));
    $processing = app(PaymentProcessingService::class);
    $verified = verifiedFromOrder($order, eventId: 'evt-inv-dup');

    $processing->recordSuccessfulPayment($verified);
    $processing->recordSuccessfulPayment($verified);

    expect(BillingInvoice::query()->where('billing_order_id', $order->getKey())->count())->toBe(1);
});

it('allocates sequential immutable invoice numbers', function (): void {
    $allocator = app(InvoiceNumberAllocator::class);
    $first = $allocator->allocate(2099);
    $second = $allocator->allocate(2099);

    expect($first)->toBe('INV-2099-000001')
        ->and($second)->toBe('INV-2099-000002');
});

it('does not create invoices for failed payments', function (): void {
    Queue::fake();
    [$user, $plan] = billingPremiumContext();
    $order = app(BillingOrderService::class)->create(billingOrderData($user, $plan, 'inv-fail'));

    app(PaymentProcessingService::class)->recordFailedPayment(
        verifiedFromOrder($order, succeeded: false, eventId: 'evt-fail'),
    );

    expect(BillingInvoice::query()->where('billing_order_id', $order->getKey())->count())->toBe(0)
        ->and($order->fresh()->status)->toBe(BillingOrderStatus::Failed);
});

it('generates deterministic pdf content and audits downloads', function (): void {
    Queue::fake();
    [$user, $plan] = billingPremiumContext();
    $order = app(BillingOrderService::class)->create(billingOrderData($user, $plan, 'inv-pdf'));
    app(PaymentProcessingService::class)->recordSuccessfulPayment(verifiedFromOrder($order, eventId: 'evt-pdf'));
    $invoice = BillingInvoice::query()->where('billing_order_id', $order->getKey())->firstOrFail();

    $pdf = app(InvoicePdfService::class);
    $first = $pdf->render($invoice, (string) $user->getKey());
    $second = $pdf->render($invoice->fresh(), (string) $user->getKey());

    expect($first)->toStartWith('%PDF')
        ->and($invoice->fresh()->content_fingerprint)->not->toBeNull()
        ->and(strlen($second))->toBeGreaterThan(100)
        ->and(AuditLog::query()->where('action', 'invoice_downloaded')->count())->toBe(2);
});

it('exposes owner billing and payment history apis', function (): void {
    Queue::fake();
    [$user, $plan] = billingPremiumContext();
    $token = issueCommercialApiKey($user, grantCommercial: true);
    $order = app(BillingOrderService::class)->create(billingOrderData($user, $plan, 'inv-api'));
    $order->forceFill(['provider' => 'fake'])->save();
    app(PaymentProcessingService::class)->recordSuccessfulPayment(verifiedFromOrder($order, eventId: 'evt-api'));
    $invoice = BillingInvoice::query()->where('billing_order_id', $order->getKey())->firstOrFail();

    $this->withToken($token)->getJson('/api/v1/billing/invoices')->assertOk()
        ->assertJsonPath('data.0.invoice_number', $invoice->invoice_number);
    $this->withToken($token)->getJson('/api/v1/billing/invoices/'.$invoice->getKey())->assertOk()
        ->assertJsonPath('data.status', 'paid');
    $this->withToken($token)->getJson('/api/v1/billing/payments')->assertOk()
        ->assertJsonPath('data.0.provider', 'fake')
        ->assertJsonMissingPath('data.0.provider_secret');
    $this->withToken($token)->getJson('/api/v1/billing/orders')->assertOk()
        ->assertJsonPath('data.0.id', $order->getKey());
    $this->withToken($token)->get('/api/v1/billing/invoices/'.$invoice->getKey().'/download')
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

it('denies cross-owner invoice download', function (): void {
    Queue::fake();
    [$owner, $plan] = billingPremiumContext();
    [$other] = billingPremiumContext();
    $token = issueCommercialApiKey($other, grantCommercial: true);
    $order = app(BillingOrderService::class)->create(billingOrderData($owner, $plan, 'inv-auth'));
    app(PaymentProcessingService::class)->recordSuccessfulPayment(verifiedFromOrder($order, eventId: 'evt-auth'));
    $invoice = BillingInvoice::query()->where('billing_order_id', $order->getKey())->firstOrFail();

    $this->withToken($token)->getJson('/api/v1/billing/invoices/'.$invoice->getKey())->assertNotFound();
    $this->withToken($token)->get('/api/v1/billing/invoices/'.$invoice->getKey().'/download')->assertNotFound();
});

it('fail-closes ledger mismatches and blocks paid void', function (): void {
    Queue::fake();
    [$user, $plan] = billingPremiumContext();
    $order = app(BillingOrderService::class)->create(billingOrderData($user, $plan, 'inv-ledger'));
    app(PaymentProcessingService::class)->recordSuccessfulPayment(verifiedFromOrder($order, eventId: 'evt-ledger'));
    $invoice = BillingInvoice::query()->where('billing_order_id', $order->getKey())->firstOrFail();

    expect(fn () => app(InvoiceService::class)->void($invoice))->toThrow(InvoiceException::class);

    $orphan = BillingOrder::query()->create([
        'user_id' => $user->getKey(),
        'plan_id' => $plan->getKey(),
        'type' => BillingOrderType::Purchase,
        'status' => BillingOrderStatus::Paid,
        'currency' => 'USD',
        'subtotal_minor' => 1000,
        'discount_minor' => 0,
        'tax_minor' => 0,
        'total_minor' => 1000,
        'provider' => 'fake',
        'idempotency_key' => 'orphan-ledger',
        'paid_at' => now(),
        'metadata' => [],
    ]);

    expect(fn () => app(InvoiceService::class)->assertLedgerConsistency($orphan))
        ->toThrow(InvoiceException::class);
});

it('supports credit note foundation without financial mutation', function (): void {
    Queue::fake();
    [$user, $plan] = billingPremiumContext();
    $order = app(BillingOrderService::class)->create(billingOrderData($user, $plan, 'inv-cn'));
    app(PaymentProcessingService::class)->recordSuccessfulPayment(verifiedFromOrder($order, eventId: 'evt-cn'));
    $invoice = BillingInvoice::query()->where('billing_order_id', $order->getKey())->firstOrFail();

    $note = app(CreditNoteService::class)->draft($invoice, 100, 'goodwill');
    $issued = app(CreditNoteService::class)->issue($note);

    expect($issued->status)->toBe(CreditNoteStatus::Issued)
        ->and(($issued->metadata ?? [])['financial_mutation'])->toBeFalse()
        ->and(PaymentTransaction::query()->where('billing_order_id', $order->getKey())
            ->where('type', PaymentTransactionType::Refund)->count())->toBe(0);
});

it('exports invoice csv for the owner', function (): void {
    Queue::fake();
    [$user, $plan] = billingPremiumContext();
    $token = issueCommercialApiKey($user, grantCommercial: true);
    $order = app(BillingOrderService::class)->create(billingOrderData($user, $plan, 'inv-csv'));
    app(PaymentProcessingService::class)->recordSuccessfulPayment(verifiedFromOrder($order, eventId: 'evt-csv'));

    $response = $this->withToken($token)->get('/api/v1/billing/invoices/export');
    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('text/csv');
});
