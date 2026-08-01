<x-filament-panels::page>
    <div class="space-y-6">
        <div class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="flex items-center justify-between gap-4">
                <div>
                    <h2 class="text-lg font-semibold">Affiliate program health</h2>
                    <p class="mt-1 text-sm text-gray-500">Evaluated at {{ $health['evaluated_at'] ?? '—' }}</p>
                </div>
                <span @class([
                    'rounded-full px-3 py-1 text-sm font-medium',
                    'bg-green-100 text-green-800' => $health['healthy'] ?? false,
                    'bg-red-100 text-red-800' => ! ($health['healthy'] ?? false),
                ])>
                    {{ ($health['healthy'] ?? false) ? 'HEALTHY' : 'ATTENTION NEEDED' }}
                </span>
            </div>
            <div class="mt-4 grid gap-3 md:grid-cols-3">
                @foreach (($health['checks'] ?? []) as $name => $check)
                    <div class="rounded-lg border p-3">
                        <p class="text-xs text-gray-500">{{ str($name)->headline() }}</p>
                        <p @class([
                            'mt-1 text-sm font-semibold',
                            'text-green-700' => ($check['status'] ?? '') === 'ok',
                            'text-amber-700' => ($check['status'] ?? '') === 'warn',
                            'text-red-700' => ($check['status'] ?? '') === 'fail',
                        ])>
                            {{ is_bool($check['value'] ?? null) ? (($check['value'] ?? false) ? 'Yes' : 'No') : ($check['value'] ?? '—') }}
                        </p>
                    </div>
                @endforeach
            </div>
            <div class="mt-4">
                <button type="button" wire:click="refreshState" class="rounded-lg border px-3 py-2 text-sm">
                    Refresh
                </button>
            </div>
        </div>

        <div class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <h3 class="text-lg font-semibold">Analytics summary</h3>
            <div class="mt-4 grid gap-4 md:grid-cols-4">
                @foreach ($analytics as $label => $value)
                    <div class="rounded-xl border p-4">
                        <p class="text-xs text-gray-500">{{ str($label)->headline() }}</p>
                        <p class="text-2xl font-semibold">{{ number_format((int) $value) }}</p>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <h3 class="text-lg font-semibold">Settings (safe keys)</h3>
            <pre class="mt-2 max-h-96 overflow-auto text-xs">{{ json_encode($settings, JSON_PRETTY_PRINT) }}</pre>
        </div>
    </div>
</x-filament-panels::page>
