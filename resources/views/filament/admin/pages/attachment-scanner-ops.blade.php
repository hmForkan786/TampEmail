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
                    <p class="ops-kicker">Attachment scanning</p>
                    <h2 class="text-lg font-semibold">Overall status</h2>
                </div>
                <span class="ops-chip {{ $chip }}">{{ $status }}</span>
            </div>
            <p class="ops-muted mt-2 text-sm">Last evaluated: {{ $evaluated_at }}</p>
        </div>

        <div class="grid gap-6 md:grid-cols-2">
            <div class="ops-card">
                <h3 class="font-semibold">Scanner readiness</h3>
                <dl class="mt-3 space-y-2 text-sm">
                    <div>State: {{ $readiness['state'] }}</div>
                    <div>Backend: {{ $readiness['backend'] }}</div>
                    <div>Configuration valid: {{ $readiness['configuration_valid'] ? 'Yes' : 'No' }}</div>
                    <div>Daemon reachable: {{ $readiness['daemon_reachable'] ? 'Yes' : 'No' }}</div>
                    <div>Protocol ready: {{ $readiness['protocol_ready'] ? 'Yes' : 'No' }}</div>
                    <div>Last success: {{ $readiness['last_successful_health_check_at'] ?? 'unknown' }}</div>
                    <div>Last failure: {{ $readiness['last_failed_health_check_at'] ?? 'unknown' }}</div>
                    <div>Failure code: {{ $readiness['failure_code'] }}</div>
                </dl>
            </div>

            <div class="ops-card ops-card--info">
                <h3 class="font-semibold">Queue health</h3>
                <dl class="mt-3 space-y-2 text-sm">
                    <div>Queue: {{ $queue['queue_name'] }}</div>
                    <div>Pending scan jobs: {{ $queue['pending_scan_jobs'] }}</div>
                    <div>Oldest pending job age: {{ $queue['oldest_pending_scan_job_age_seconds'] }} seconds</div>
                    <div>Failed scan jobs: {{ $queue['failed_scan_jobs'] }}</div>
                    <div>Retry backlog: {{ $queue['retry_backlog'] }}</div>
                    <div>Currently processing: {{ $queue['currently_processing'] }}</div>
                    <div>Oldest pending attachment age: {{ $queue['oldest_pending_attachment_age_seconds'] }} seconds</div>
                </dl>
            </div>

            <div class="ops-card">
                <h3 class="font-semibold">Counts (24 hours)</h3>
                <dl class="mt-3 space-y-2 text-sm">
                    @foreach ($counts['last_24_hours'] as $label => $value)
                        <div>{{ str_replace('_', ' ', $label) }}: {{ $value }}</div>
                    @endforeach
                </dl>
            </div>

            <div class="ops-card">
                <h3 class="font-semibold">Counts (7 days)</h3>
                <dl class="mt-3 space-y-2 text-sm">
                    @foreach ($counts['last_7_days'] as $label => $value)
                        <div>{{ str_replace('_', ' ', $label) }}: {{ $value }}</div>
                    @endforeach
                </dl>
            </div>

            <div class="ops-card ops-card--warn">
                <h3 class="font-semibold">Quarantine overview</h3>
                <dl class="mt-3 space-y-2 text-sm">
                    <div>Infected: {{ $quarantine['infected_count'] }}</div>
                    <div>Failed: {{ $quarantine['failed_count'] }}</div>
                    <div>Awaiting review: {{ $quarantine['awaiting_review'] }}</div>
                    <div>Oldest quarantined age: {{ $quarantine['oldest_quarantined_age_seconds'] }} seconds</div>
                    <div>Permanent deletions (24h): {{ $quarantine['recent_permanent_deletions_24h'] }}</div>
                </dl>
            </div>

            <div class="ops-card ops-card--accent">
                <h3 class="font-semibold">Live check</h3>
                @if ($live_check)
                    <dl class="mt-3 space-y-2 text-sm">
                        <div>Status: {{ $live_check['status'] }}</div>
                        <div>Backend: {{ $live_check['backend'] }}</div>
                        <div>Clean probe: {{ $live_check['clean_probe'] }}</div>
                        <div>Infected probe: {{ $live_check['infected_probe'] }}</div>
                        <div>Issues: {{ $live_check['issues'] === [] ? 'none' : implode(', ', $live_check['issues']) }}</div>
                    </dl>
                @else
                    <p class="ops-muted mt-3 text-sm">Not run on page load. Use “Run live check” for an explicit infected-content probe.</p>
                @endif
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
