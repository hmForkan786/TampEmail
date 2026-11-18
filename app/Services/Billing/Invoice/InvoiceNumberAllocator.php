<?php

declare(strict_types=1);

namespace App\Services\Billing\Invoice;

use Illuminate\Support\Facades\DB;

/**
 * Allocates unique immutable invoice numbers: INV-YYYY-000001.
 */
final class InvoiceNumberAllocator
{
    public function allocate(?int $year = null): string
    {
        $year ??= (int) now()->format('Y');
        $prefix = strtoupper(trim((string) config('billing.invoice.prefix', 'INV')));
        if ($prefix === '' || ! preg_match('/^[A-Z0-9]{1,10}$/', $prefix)) {
            $prefix = 'INV';
        }

        $padding = max(4, min(8, (int) config('billing.invoice.number_padding', 6)));

        return DB::transaction(function () use ($year, $prefix, $padding): string {
            $row = DB::table('billing_invoice_sequences')->where('year', $year)->lockForUpdate()->first();
            if ($row === null) {
                DB::table('billing_invoice_sequences')->insert([
                    'year' => $year,
                    'last_number' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $row = DB::table('billing_invoice_sequences')->where('year', $year)->lockForUpdate()->first();
            }

            $next = (int) $row->last_number + 1;
            DB::table('billing_invoice_sequences')->where('year', $year)->update([
                'last_number' => $next,
                'updated_at' => now(),
            ]);

            return sprintf('%s-%d-%s', $prefix, $year, str_pad((string) $next, $padding, '0', STR_PAD_LEFT));
        });
    }
}
