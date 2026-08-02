<x-filament-panels::page>
    <div class="space-y-6">
        <div class="flex items-center justify-between gap-4">
            <div>
                <h2 class="text-lg font-semibold">Aggregation health</h2>
                <p class="mt-1 text-sm text-gray-500">Read-model pipeline only — never mutates Billing, Mail, or API.</p>
            </div>
            <div class="flex gap-3">
                <button type="button" wire:click="refreshState" class="rounded-lg border px-3 py-2 text-sm">Refresh</button>
                <button
                    type="button"
                    wire:click="runBackfill"
                    wire:confirm="Run analytics backfill for missing days?"
                    class="rounded-lg bg-primary-600 px-3 py-2 text-sm text-white"
                >
                    Run backfill
                </button>
            </div>
        </div>

        <div class="grid gap-4 md:grid-cols-4">
            <div class="rounded-xl border p-4">
                <p class="text-xs text-gray-500">Status</p>
                <p class="text-2xl font-semibold">{{ ($health['healthy'] ?? false) ? 'Healthy' : 'Degraded' }}</p>
            </div>
            <div class="rounded-xl border p-4">
                <p class="text-xs text-gray-500">Backlog days</p>
                <p class="text-2xl font-semibold">{{ number_format((int) ($health['backlog_days'] ?? 0)) }}</p>
            </div>
            <div class="rounded-xl border p-4">
                <p class="text-xs text-gray-500">Failed (24h)</p>
                <p class="text-2xl font-semibold">{{ number_format((int) ($health['failed_runs_24h'] ?? 0)) }}</p>
            </div>
            <div class="rounded-xl border p-4">
                <p class="text-xs text-gray-500">Rollups</p>
                <p class="text-2xl font-semibold">{{ number_format((int) ($health['rollups_total'] ?? 0)) }}</p>
            </div>
        </div>

        <div class="rounded-xl border p-4 text-sm">
            <h3 class="font-semibold">Health JSON</h3>
            <pre class="mt-2 overflow-x-auto text-xs">{{ json_encode($health, JSON_PRETTY_PRINT) }}</pre>
        </div>

        <div class="rounded-xl border p-4 text-sm">
            <h3 class="font-semibold">Safe settings</h3>
            <pre class="mt-2 overflow-x-auto text-xs">{{ json_encode($settings, JSON_PRETTY_PRINT) }}</pre>
        </div>
    </div>
</x-filament-panels::page>
