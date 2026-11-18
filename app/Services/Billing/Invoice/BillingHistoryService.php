<?php

declare(strict_types=1);

namespace App\Services\Billing\Invoice;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentTransactionType;
use App\Models\BillingInvoice;
use App\Models\BillingOrder;
use App\Models\PaymentTransaction;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Owner billing history: invoices, payments, orders. Newest first, cursor pagination.
 */
final class BillingHistoryService
{
    /** @param array<string, mixed> $filters */
    public function invoices(string $userId, array $filters = [], int $perPage = 25): CursorPaginator
    {
        $query = BillingInvoice::query()
            ->with(['lineItems'])
            ->where('user_id', $userId)
            ->latest('issued_at')
            ->latest('id');

        $this->applyInvoiceFilters($query, $filters);

        return $query->cursorPaginate($this->clampPerPage($perPage));
    }

    /** @param array<string, mixed> $filters */
    public function payments(string $userId, array $filters = [], int $perPage = 25): CursorPaginator
    {
        $query = PaymentTransaction::query()
            ->where('user_id', $userId)
            ->where('status', PaymentTransactionStatus::Succeeded)
            ->whereIn('type', [PaymentTransactionType::Sale, PaymentTransactionType::Capture])
            ->latest('processed_at')
            ->latest('id');

        if (! empty($filters['provider'])) {
            $query->where('provider', (string) $filters['provider']);
        }
        if (! empty($filters['from'])) {
            $query->where('processed_at', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->where('processed_at', '<=', $filters['to']);
        }

        return $query->cursorPaginate($this->clampPerPage($perPage));
    }

    /** @param array<string, mixed> $filters */
    public function orders(string $userId, array $filters = [], int $perPage = 25): CursorPaginator
    {
        $query = BillingOrder::query()
            ->where('user_id', $userId)
            ->latest('created_at')
            ->latest('id');

        if (! empty($filters['status'])) {
            $query->where('status', (string) $filters['status']);
        }
        if (! empty($filters['provider'])) {
            $query->where('provider', (string) $filters['provider']);
        }
        if (! empty($filters['subscription_id'])) {
            $query->where('subscription_id', (string) $filters['subscription_id']);
        }

        return $query->cursorPaginate($this->clampPerPage($perPage));
    }

    public function ownedInvoice(string $invoiceId, string $userId): BillingInvoice
    {
        return BillingInvoice::query()
            ->with(['lineItems', 'billingOrder'])
            ->whereKey($invoiceId)
            ->where('user_id', $userId)
            ->firstOrFail();
    }

    public function invoiceById(string $invoiceId): BillingInvoice
    {
        return BillingInvoice::query()->with(['lineItems', 'billingOrder', 'user'])->whereKey($invoiceId)->firstOrFail();
    }

    /** @param array<string, mixed> $filters */
    public function exportInvoicesCsv(string $userId, array $filters = []): StreamedResponse
    {
        $query = BillingInvoice::query()->where('user_id', $userId)->latest('issued_at')->latest('id');
        $this->applyInvoiceFilters($query, $filters);

        return response()->streamDownload(function () use ($query): void {
            $handle = fopen('php://output', 'w');
            if ($handle === false) {
                return;
            }
            fputcsv($handle, [
                'invoice_number', 'status', 'currency', 'subtotal_minor', 'tax_minor',
                'discount_minor', 'total_minor', 'provider', 'provider_reference',
                'issued_at', 'paid_at', 'subscription_id',
            ]);
            $query->chunkById(200, function (Collection $rows) use ($handle): void {
                foreach ($rows as $invoice) {
                    /** @var BillingInvoice $invoice */
                    fputcsv($handle, [
                        $invoice->invoice_number,
                        $invoice->status->value,
                        $invoice->currency,
                        $invoice->subtotal_minor,
                        $invoice->tax_minor,
                        $invoice->discount_minor,
                        $invoice->total_minor,
                        $invoice->provider,
                        $invoice->provider_reference,
                        $invoice->issued_at?->toIso8601String(),
                        $invoice->paid_at?->toIso8601String(),
                        $invoice->subscription_id,
                    ]);
                }
            });
            fclose($handle);
        }, 'billing-invoices.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array<string, mixed> */
    public function projectInvoice(BillingInvoice $invoice): array
    {
        return [
            'id' => $invoice->getKey(),
            'invoice_number' => $invoice->invoice_number,
            'status' => $invoice->status->value,
            'currency' => $invoice->currency,
            'subtotal_minor' => $invoice->subtotal_minor,
            'tax_minor' => $invoice->tax_minor,
            'discount_minor' => $invoice->discount_minor,
            'total_minor' => $invoice->total_minor,
            'provider' => $invoice->provider,
            'provider_reference' => $invoice->provider_reference,
            'billing_order_id' => $invoice->billing_order_id,
            'subscription_id' => $invoice->subscription_id,
            'issued_at' => $invoice->issued_at?->toIso8601String(),
            'paid_at' => $invoice->paid_at?->toIso8601String(),
            'voided_at' => $invoice->voided_at?->toIso8601String(),
            'line_items' => $invoice->lineItems->map(static fn ($item): array => [
                'description' => $item->description,
                'quantity' => $item->quantity,
                'unit_price_minor' => $item->unit_price_minor,
                'line_total_minor' => $item->line_total_minor,
            ])->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function projectPayment(PaymentTransaction $tx): array
    {
        $invoice = BillingInvoice::query()->where('billing_order_id', $tx->billing_order_id)->first();

        return [
            'date' => ($tx->processed_at ?? $tx->created_at)?->toIso8601String(),
            'invoice' => $invoice?->invoice_number,
            'invoice_id' => $invoice?->getKey(),
            'provider' => $tx->provider,
            'amount_minor' => $tx->amount_minor,
            'currency' => $tx->currency,
            'status' => $tx->status->value,
            'reference' => $tx->provider_transaction_id,
        ];
    }

    /** @return array<string, mixed> */
    public function projectOrder(BillingOrder $order): array
    {
        return [
            'id' => $order->getKey(),
            'type' => $order->type->value,
            'status' => $order->status->value,
            'currency' => $order->currency,
            'total_minor' => $order->total_minor,
            'provider' => $order->provider,
            'provider_reference' => $order->provider_reference,
            'subscription_id' => $order->subscription_id,
            'paid_at' => $order->paid_at?->toIso8601String(),
            'created_at' => $order->created_at?->toIso8601String(),
        ];
    }

    /**
     * @param  Builder<BillingInvoice>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyInvoiceFilters(Builder $query, array $filters): void
    {
        if (! empty($filters['invoice_number'])) {
            $query->where('invoice_number', (string) $filters['invoice_number']);
        }
        if (! empty($filters['status'])) {
            $status = InvoiceStatus::tryFrom((string) $filters['status']);
            if ($status !== null) {
                $query->where('status', $status);
            }
        }
        if (! empty($filters['provider'])) {
            $query->where('provider', (string) $filters['provider']);
        }
        if (! empty($filters['subscription_id'])) {
            $query->where('subscription_id', (string) $filters['subscription_id']);
        }
        if (! empty($filters['from'])) {
            $query->where('issued_at', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->where('issued_at', '<=', $filters['to']);
        }
    }

    private function clampPerPage(int $perPage): int
    {
        return min(100, max(1, $perPage));
    }
}
