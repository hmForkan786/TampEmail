<x-filament-panels::page>
    <div class="space-y-6">
        <div class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="flex items-center justify-between"><h2 class="text-lg font-semibold">Overall status</h2><span class="rounded-full px-3 py-1 text-sm font-medium">{{ $status }}</span></div>
            <p class="mt-2 text-sm text-gray-500">Last evaluated: {{ $evaluated_at }}</p>
        </div>
        <div class="grid gap-6 md:grid-cols-2">
            <div class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10"><h3 class="font-semibold">Queue</h3><dl class="mt-3 space-y-2 text-sm"><div>Connection: {{ $queue['connection'] }}</div><div>Backlog: {{ $queue['backlog'] }}</div><div>Oldest job age: {{ $queue['oldest_job_age_seconds'] }} seconds</div><div>Failed jobs: {{ $queue['failed_jobs'] }}</div></dl></div>
            <div class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10"><h3 class="font-semibold">Cache / lock store</h3><dl class="mt-3 space-y-2 text-sm"><div>Store: {{ $lock_store['cache'] }}</div><div>Compatible: {{ $lock_store['compatible'] ? 'Yes' : 'No' }}</div></dl></div>
            <div class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10"><h3 class="font-semibold">Workers</h3><div class="mt-3 text-sm">Fresh: {{ $worker['fresh_count'] }} / {{ $worker['expected_count'] }}</div>@foreach($worker['records'] as $record)<div class="mt-3 border-t pt-3 text-sm">{{ $record['process_type'] }} · {{ implode(', ', $record['queue_names']) }} · {{ $record['status'] }} · {{ $record['identifier'] }}<br>Heartbeat: {{ $record['heartbeat_at'] ?? 'unknown' }}</div>@endforeach</div>
            <div class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10"><h3 class="font-semibold">Scheduler</h3><dl class="mt-3 space-y-2 text-sm"><div>Fresh: {{ $scheduler['fresh'] ? 'Yes' : 'No' }}</div><div>Status: {{ $scheduler['status'] }}</div><div>Heartbeat: {{ $scheduler['heartbeat_at'] ?? 'unknown' }}</div></dl></div>
        </div>
        <div class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10"><h3 class="font-semibold">Reasons</h3><ul class="mt-2 list-disc pl-5 text-sm">@forelse($issues as $issue)<li>{{ $issue }}</li>@empty<li>No reported issues.</li>@endforelse</ul></div>
    </div>
</x-filament-panels::page>
