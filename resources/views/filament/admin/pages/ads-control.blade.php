<x-filament-panels::page>
    <div class="space-y-6">
        <div class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="flex items-center justify-between gap-4">
                <div>
                    <h2 class="text-lg font-semibold">Emergency stop</h2>
                    <p class="mt-1 text-sm text-gray-500">Halts all monetization and promotion renders immediately.</p>
                </div>
                <span @class([
                    'rounded-full px-3 py-1 text-sm font-medium',
                    'bg-red-100 text-red-800' => $emergency_stop,
                    'bg-green-100 text-green-800' => ! $emergency_stop,
                ])>
                    {{ $emergency_stop ? 'STOPPED' : 'LIVE' }}
                </span>
            </div>
            <div class="mt-4 flex gap-3">
                @if ($emergency_stop)
                    <button
                        type="button"
                        wire:click="releaseEmergencyStop"
                        wire:confirm="Release ads emergency stop and resume serving?"
                        class="rounded-lg bg-primary-600 px-3 py-2 text-sm text-white"
                    >
                        Release stop
                    </button>
                @else
                    <button
                        type="button"
                        wire:click="engageEmergencyStop"
                        wire:confirm="Engage ads emergency stop for all placements?"
                        class="rounded-lg bg-red-600 px-3 py-2 text-sm text-white"
                    >
                        Engage emergency stop
                    </button>
                @endif
                <button type="button" wire:click="refreshState" class="rounded-lg border px-3 py-2 text-sm">
                    Refresh
                </button>
            </div>
        </div>

        <div class="grid gap-4 md:grid-cols-4">
            <div class="rounded-xl border p-4">
                <p class="text-xs text-gray-500">Impressions</p>
                <p class="text-2xl font-semibold">{{ number_format($stats['impressions'] ?? 0) }}</p>
            </div>
            <div class="rounded-xl border p-4">
                <p class="text-xs text-gray-500">Clicks</p>
                <p class="text-2xl font-semibold">{{ number_format($stats['clicks'] ?? 0) }}</p>
            </div>
            <div class="rounded-xl border p-4">
                <p class="text-xs text-gray-500">CTR %</p>
                <p class="text-2xl font-semibold">{{ number_format($stats['ctr'] ?? 0, 2) }}</p>
            </div>
            <div class="rounded-xl border p-4">
                <p class="text-xs text-gray-500">Revenue (minor)</p>
                <p class="text-2xl font-semibold">{{ number_format($stats['revenue_minor'] ?? 0) }}</p>
            </div>
        </div>

        <div class="rounded-xl border p-4 text-sm">
            <h3 class="font-semibold">Health</h3>
            <pre class="mt-2 overflow-x-auto text-xs">{{ json_encode($health, JSON_PRETTY_PRINT) }}</pre>
        </div>
    </div>
</x-filament-panels::page>
