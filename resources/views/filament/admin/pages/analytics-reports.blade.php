<x-filament-panels::page>
    <div class="space-y-6">
        <div class="rounded-xl border p-4">
            <h2 class="text-lg font-semibold">Report filters</h2>
            <div class="mt-4 grid gap-4 md:grid-cols-4">
                <label class="text-sm">
                    <span class="text-gray-500">Period</span>
                    <select wire:model="period" class="mt-1 w-full rounded-lg border px-3 py-2">
                        @foreach ($this->periodOptions() as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="text-sm">
                    <span class="text-gray-500">From</span>
                    <input type="date" wire:model="from" class="mt-1 w-full rounded-lg border px-3 py-2" />
                </label>
                <label class="text-sm">
                    <span class="text-gray-500">To</span>
                    <input type="date" wire:model="to" class="mt-1 w-full rounded-lg border px-3 py-2" />
                </label>
                <label class="text-sm">
                    <span class="text-gray-500">Domain</span>
                    <select wire:model="domain" class="mt-1 w-full rounded-lg border px-3 py-2">
                        @foreach ($this->domainOptions() as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
            </div>
            <div class="mt-4 flex gap-3">
                <button type="button" wire:click="refreshReport" class="rounded-lg bg-primary-600 px-3 py-2 text-sm text-white">
                    Run report
                </button>
                <button type="button" wire:click="exportCsv" class="rounded-lg border px-3 py-2 text-sm">
                    CSV export
                </button>
            </div>
        </div>

        <div class="rounded-xl border p-4 overflow-x-auto">
            <h3 class="font-semibold">
                {{ strtoupper($report['period'] ?? 'daily') }}
                · {{ $report['from'] ?? '—' }} → {{ $report['to'] ?? '—' }}
                · {{ number_format(count($report['rows'] ?? [])) }} rows
            </h3>
            <table class="mt-3 w-full text-left text-sm">
                <thead>
                    <tr class="border-b text-gray-500">
                        <th class="py-2 pr-3">Date</th>
                        <th class="py-2 pr-3">Domain</th>
                        <th class="py-2 pr-3">Metric</th>
                        <th class="py-2">Value</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse (($report['rows'] ?? []) as $row)
                        <tr class="border-b border-gray-100 dark:border-gray-800">
                            <td class="py-1.5 pr-3">{{ $row['date'] }}</td>
                            <td class="py-1.5 pr-3">{{ $row['domain'] }}</td>
                            <td class="py-1.5 pr-3">{{ $row['metric_key'] }}</td>
                            <td class="py-1.5">{{ number_format((float) $row['value'], 4) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="py-6 text-center text-gray-500">
                                No rollup rows yet. Run <code>php artisan analytics:rollup --backfill</code>.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-filament-panels::page>
