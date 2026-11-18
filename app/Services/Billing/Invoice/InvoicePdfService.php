<?php

declare(strict_types=1);

namespace App\Services\Billing\Invoice;

use App\Enums\InvoiceStatus;
use App\Exceptions\Billing\InvoiceException;
use App\Models\BillingInvoice;
use App\Services\Audit\AuditLogWriter;
use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Deterministic PDF generation from issued invoice snapshots.
 */
final class InvoicePdfService
{
    public function __construct(
        private readonly AuditLogWriter $audit,
    ) {}

    public function render(BillingInvoice $invoice, ?string $actorUserId = null): string
    {
        if (! in_array($invoice->status, [InvoiceStatus::Issued, InvoiceStatus::Paid], true)) {
            throw InvoiceException::invalidTransition('PDF is only available for issued invoices.');
        }

        $invoice->loadMissing(['lineItems']);
        $html = $this->html($invoice);
        $fingerprint = hash('sha256', $html);

        if ($invoice->content_fingerprint === null) {
            $invoice->forceFill(['content_fingerprint' => $fingerprint])->save();
        } elseif (! hash_equals($invoice->content_fingerprint, $fingerprint)) {
            throw InvoiceException::ledgerMismatch('Invoice PDF content fingerprint mismatch.', [
                'invoice_number' => $invoice->invoice_number,
            ]);
        }

        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4');
        $dompdf->render();

        $this->audit->write('invoice_downloaded', $actorUserId ?? $invoice->user_id, $invoice, null, [
            'invoice_number' => $invoice->invoice_number,
        ]);

        return $dompdf->output();
    }

    public function contentFingerprint(BillingInvoice $invoice): string
    {
        $invoice->loadMissing(['lineItems']);

        return hash('sha256', $this->html($invoice));
    }

    private function html(BillingInvoice $invoice): string
    {
        $customer = e((string) (($invoice->metadata ?? [])['customer_label'] ?? 'Customer'));
        $invoiceNumber = e((string) $invoice->invoice_number);
        $issued = e((string) ($invoice->issued_at?->toDateString() ?? ''));
        $paid = e((string) ($invoice->paid_at?->toDateString() ?? '—'));
        $provider = e((string) $invoice->provider);
        $reference = e((string) $invoice->provider_reference);
        $currency = e($invoice->currency);
        $status = e($invoice->status->value);

        $lines = '';
        foreach ($invoice->lineItems as $item) {
            $lines .= '<tr><td>'.e($item->description).'</td><td>'.$item->quantity.'</td><td>'
                .e($this->formatMoney($item->unit_price_minor, $invoice->currency)).'</td><td>'
                .e($this->formatMoney($item->line_total_minor, $invoice->currency)).'</td></tr>';
        }

        $subtotal = e($this->formatMoney($invoice->subtotal_minor, $invoice->currency));
        $discount = e($this->formatMoney($invoice->discount_minor, $invoice->currency));
        $tax = e($this->formatMoney($invoice->tax_minor, $invoice->currency));
        $total = e($this->formatMoney($invoice->total_minor, $invoice->currency));

        return <<<HTML
<!DOCTYPE html>
<html><head><meta charset="utf-8"><style>
body{font-family:DejaVu Sans,sans-serif;font-size:12px;color:#111;margin:40px}
h1{font-size:20px;margin:0 0 8px}
table{width:100%;border-collapse:collapse;margin-top:20px}
th,td{border-bottom:1px solid #ddd;padding:8px;text-align:left}
.meta{margin-top:12px;line-height:1.5}
.totals{margin-top:20px;width:40%;margin-left:auto}
</style></head><body>
<h1>Invoice {$invoiceNumber}</h1>
<div class="meta">
<div>Customer: {$customer}</div>
<div>Issue date: {$issued}</div>
<div>Paid date: {$paid}</div>
<div>Provider: {$provider}</div>
<div>Payment reference: {$reference}</div>
<div>Currency: {$currency}</div>
<div>Status: {$status}</div>
</div>
<table>
<thead><tr><th>Description</th><th>Qty</th><th>Unit</th><th>Total</th></tr></thead>
<tbody>{$lines}</tbody>
</table>
<table class="totals">
<tr><td>Subtotal</td><td>{$subtotal}</td></tr>
<tr><td>Discount</td><td>{$discount}</td></tr>
<tr><td>Tax</td><td>{$tax}</td></tr>
<tr><td><strong>Total</strong></td><td><strong>{$total}</strong></td></tr>
</table>
</body></html>
HTML;
    }

    private function formatMoney(int $minor, string $currency): string
    {
        return sprintf('%s %.2f', strtoupper($currency), $minor / 100);
    }
}
