<x-filament-panels::page>
    <div class="space-y-6">
        <div class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="flex items-center justify-between">
                <h2 class="text-lg font-semibold">Overall status</h2>
                <span class="rounded-full px-3 py-1 text-sm font-medium">{{ $status }}</span>
            </div>
            <p class="mt-2 text-sm text-gray-500">Last evaluated: {{ $evaluated_at ?? 'unknown' }}</p>
            <p class="mt-1 text-sm text-gray-500">Page load never sends an external test email.</p>
        </div>

        <div class="grid gap-6 md:grid-cols-2">
            <div class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <h3 class="font-semibold">Transport readiness</h3>
                <dl class="mt-3 space-y-2 text-sm">
                    <div>State: {{ $readiness['state'] ?? 'unknown' }}</div>
                    <div>Transport: {{ $readiness['transport'] ?? 'unknown' }}</div>
                    <div>Configuration valid: {{ !empty($readiness['configuration_valid']) ? 'Yes' : 'No' }}</div>
                    <div>Recent sent: {{ $readiness['recent_sent_at'] ?? 'none' }}</div>
                    <div>Recent failed: {{ $readiness['recent_failed_at'] ?? 'none' }}</div>
                    <div>Failure code: {{ $readiness['recent_failure_code'] ?? $readiness['failure_code'] ?? 'none' }}</div>
                </dl>
            </div>

            <div class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <h3 class="font-semibold">Retry metrics</h3>
                <dl class="mt-3 space-y-2 text-sm">
                    @foreach(($retries ?? []) as $label => $value)
                        <div>{{ str_replace('_', ' ', $label) }}: {{ $value }}</div>
                    @endforeach
                </dl>
            </div>

            <div class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <h3 class="font-semibold">Volume (24 hours)</h3>
                <dl class="mt-3 space-y-2 text-sm">
                    @foreach(($volume['last_24_hours'] ?? []) as $label => $value)
                        <div>{{ str_replace('_', ' ', $label) }}: {{ $value }}</div>
                    @endforeach
                </dl>
            </div>

            <div class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <h3 class="font-semibold">Volume (7 days)</h3>
                <dl class="mt-3 space-y-2 text-sm">
                    @foreach(($volume['last_7_days'] ?? []) as $label => $value)
                        <div>{{ str_replace('_', ' ', $label) }}: {{ $value }}</div>
                    @endforeach
                </dl>
            </div>

            <div class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <h3 class="font-semibold">Provider metrics (24 hours)</h3>
                <dl class="mt-3 space-y-2 text-sm">
                    @foreach(($provider ?? []) as $label => $value)
                        <div>{{ str_replace('_', ' ', $label) }}: {{ $value }}</div>
                    @endforeach
                </dl>
            </div>

            <div class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <h3 class="font-semibold">Suppressions</h3>
                <dl class="mt-3 space-y-2 text-sm">
                    @foreach(($suppressions ?? []) as $label => $value)
                        <div>{{ str_replace('_', ' ', $label) }}: {{ $value }}</div>
                    @endforeach
                </dl>
            </div>

            <div class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <h3 class="font-semibold">Abuse controls</h3>
                <dl class="mt-3 space-y-2 text-sm">
                    @foreach(($abuse ?? []) as $label => $value)
                        <div>{{ str_replace('_', ' ', $label) }}: {{ $value }}</div>
                    @endforeach
                </dl>
            </div>

            <div class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <h3 class="font-semibold">Issues</h3>
                <p class="mt-3 text-sm">{{ ($issues ?? []) === [] ? 'none' : implode(', ', $issues) }}</p>
            </div>
        </div>
    </div>
</x-filament-panels::page>
