<x-filament-panels::page>
    @php
        $statusKey = strtolower((string) ($status ?? 'unknown'));
        $chip = match (true) {
            in_array($statusKey, ['ready', 'healthy', 'ok', 'pass'], true) => 'ops-chip--ready',
            in_array($statusKey, ['degraded', 'warning', 'warn'], true) => 'ops-chip--warn',
            in_array($statusKey, ['failed', 'error', 'blocked', 'critical'], true) => 'ops-chip--critical',
            default => 'ops-chip--info',
        };
    @endphp

    <div class="temail-ops space-y-6">
        <div class="ops-card ops-card--hero">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <p class="ops-kicker">Process runtime</p>
                    <h2 class="text-lg font-semibold">Overall status</h2>
                </div>
                <span class="ops-chip {{ $chip }}">{{ $status }}</span>
            </div>
            <p class="ops-muted mt-2 text-sm">Last evaluated: {{ $evaluated_at }}</p>
        </div>

        <div class="grid gap-6 md:grid-cols-2">
            <div class="ops-card ops-card--info">
                <h3 class="font-semibold">Queue</h3>
                <dl class="mt-3 space-y-2 text-sm">
                    <div>Connection: {{ $queue['connection'] }}</div>
                    <div>Backlog: {{ $queue['backlog'] }}</div>
                    <div>Oldest job age: {{ $queue['oldest_job_age_seconds'] }} seconds</div>
                    <div>Failed jobs: {{ $queue['failed_jobs'] }}</div>
                </dl>
            </div>

            <div class="ops-card ops-card--accent">
                <h3 class="font-semibold">Cache / lock store</h3>
                <dl class="mt-3 space-y-2 text-sm">
                    <div>Store: {{ $lock_store['cache'] }}</div>
                    <div>Compatible: {{ $lock_store['compatible'] ? 'Yes' : 'No' }}</div>
                </dl>
            </div>

            <div class="ops-card">
                <h3 class="font-semibold">Workers</h3>
                <div class="mt-3 text-sm">Fresh: {{ $worker['fresh_count'] }} / {{ $worker['expected_count'] }}</div>
                @foreach ($worker['records'] as $record)
                    <div class="ops-metric mt-3 text-sm">
                        {{ $record['process_type'] }} · {{ implode(', ', $record['queue_names']) }} · {{ $record['status'] }} · {{ $record['identifier'] }}
                        <br>Heartbeat: {{ $record['heartbeat_at'] ?? 'unknown' }}
                    </div>
                @endforeach
            </div>

            <div class="ops-card">
                <h3 class="font-semibold">Scheduler</h3>
                <dl class="mt-3 space-y-2 text-sm">
                    <div>Fresh: {{ $scheduler['fresh'] ? 'Yes' : 'No' }}</div>
                    <div>Status: {{ $scheduler['status'] }}</div>
                    <div>Heartbeat: {{ $scheduler['heartbeat_at'] ?? 'unknown' }}</div>
                </dl>
            </div>
        </div>

        <div class="ops-card {{ ($issues ?? []) === [] ? '' : 'ops-card--warn' }}">
            <h3 class="font-semibold">Reasons</h3>
            <ul class="mt-2 list-disc pl-5 text-sm">
                @forelse ($issues as $issue)
                    <li>{{ $issue }}</li>
                @empty
                    <li>No reported issues.</li>
                @endforelse
            </ul>
        </div>
    </div>
</x-filament-panels::page>
