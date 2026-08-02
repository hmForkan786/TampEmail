<x-filament-panels::page>
    @php
        $statusKey = strtolower((string) ($status ?? 'unknown'));
        $chip = match (true) {
            in_array($statusKey, ['ready', 'healthy', 'ok', 'pass'], true) => 'ops-chip--ready',
            in_array($statusKey, ['degraded', 'warning', 'warn'], true) => 'ops-chip--warn',
            in_array($statusKey, ['failed', 'error', 'blocked', 'critical'], true) => 'ops-chip--critical',
            in_array($statusKey, ['disabled', 'unknown'], true) => 'ops-chip--accent',
            default => 'ops-chip--info',
        };
    @endphp

    <div class="temail-ops space-y-6">
        <div class="ops-card ops-card--hero">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <p class="ops-kicker">Outbound operations</p>
                    <h2 class="text-lg font-semibold">Overall status</h2>
                </div>
                <span class="ops-chip {{ $chip }}">{{ $status }}</span>
            </div>
            <p class="ops-muted mt-2 text-sm">Last evaluated: {{ $evaluated_at ?? 'unknown' }}</p>
            <p class="ops-muted mt-1 text-sm">Page load never sends an external test email.</p>
        </div>

        <div class="grid gap-6 md:grid-cols-2">
            <div class="ops-card">
                <h3 class="font-semibold">Transport readiness</h3>
                <dl class="mt-3 space-y-2 text-sm">
                    <div>State: {{ $readiness['state'] ?? 'unknown' }}</div>
                    <div>Transport: {{ $readiness['transport'] ?? 'unknown' }}</div>
                    <div>Configuration valid: {{ ! empty($readiness['configuration_valid']) ? 'Yes' : 'No' }}</div>
                    <div>Recent sent: {{ $readiness['recent_sent_at'] ?? 'none' }}</div>
                    <div>Recent failed: {{ $readiness['recent_failed_at'] ?? 'none' }}</div>
                    <div>Failure code: {{ $readiness['recent_failure_code'] ?? $readiness['failure_code'] ?? 'none' }}</div>
                </dl>
            </div>

            <div class="ops-card ops-card--info">
                <h3 class="font-semibold">Retry metrics</h3>
                <dl class="mt-3 space-y-2 text-sm">
                    @foreach (($retries ?? []) as $label => $value)
                        <div>{{ str_replace('_', ' ', $label) }}: {{ $value }}</div>
                    @endforeach
                </dl>
            </div>

            <div class="ops-card">
                <h3 class="font-semibold">Volume (24 hours)</h3>
                <dl class="mt-3 space-y-2 text-sm">
                    @foreach (($volume['last_24_hours'] ?? []) as $label => $value)
                        <div>{{ str_replace('_', ' ', $label) }}: {{ $value }}</div>
                    @endforeach
                </dl>
            </div>

            <div class="ops-card">
                <h3 class="font-semibold">Volume (7 days)</h3>
                <dl class="mt-3 space-y-2 text-sm">
                    @foreach (($volume['last_7_days'] ?? []) as $label => $value)
                        <div>{{ str_replace('_', ' ', $label) }}: {{ $value }}</div>
                    @endforeach
                </dl>
            </div>

            <div class="ops-card ops-card--accent">
                <h3 class="font-semibold">Provider metrics (24 hours)</h3>
                <dl class="mt-3 space-y-2 text-sm">
                    @foreach (($provider ?? []) as $label => $value)
                        <div>{{ str_replace('_', ' ', $label) }}: {{ $value }}</div>
                    @endforeach
                </dl>
            </div>

            <div class="ops-card ops-card--warn">
                <h3 class="font-semibold">Suppressions</h3>
                <dl class="mt-3 space-y-2 text-sm">
                    @foreach (($suppressions ?? []) as $label => $value)
                        <div>{{ str_replace('_', ' ', $label) }}: {{ $value }}</div>
                    @endforeach
                </dl>
            </div>

            <div class="ops-card">
                <h3 class="font-semibold">Abuse controls</h3>
                <dl class="mt-3 space-y-2 text-sm">
                    @foreach (($abuse ?? []) as $label => $value)
                        <div>{{ str_replace('_', ' ', $label) }}: {{ $value }}</div>
                    @endforeach
                </dl>
            </div>

            <div class="ops-card md:col-span-2">
                <h3 class="font-semibold">Provider portability</h3>
                <p class="ops-muted mt-1 text-xs">Automatic cross-provider failover is not implemented — see docs/OUTBOUND_PROVIDER_PORTABILITY.md. Manual retry below is the only cross-provider path.</p>
                <dl class="mt-3 space-y-2 text-sm">
                    <div>Primary provider: {{ $providers['primary_provider'] ?? 'unknown' }}</div>
                    <div>Secondary provider: {{ $providers['secondary_provider'] ?? 'none configured' }}</div>
                    <div>Failover enabled (defense-in-depth flag only): {{ ! empty($providers['failover_enabled']) ? 'Yes' : 'No' }}</div>
                    <div>Configuration errors: {{ empty($providers['config_errors']) ? 'none' : implode(', ', $providers['config_errors']) }}</div>
                </dl>

                @if (! empty($providers['readiness']))
                    <div class="mt-4 grid gap-3 md:grid-cols-2">
                        @foreach ($providers['readiness'] as $name => $r)
                            <div class="ops-metric text-xs">
                                <strong>{{ $name }}</strong>
                                <div class="mt-2 space-y-1">
                                    <div>Ready: {{ ! empty($r['ready']) ? 'Yes' : 'No' }}</div>
                                    <div>Parser resolves: {{ ! empty($r['parser_resolves']) ? 'Yes' : 'No' }}</div>
                                    <div>Webhook secret present: {{ ! empty($r['webhook_secret_present']) ? 'Yes' : 'No' }}</div>
                                    <div>Domain verified count: {{ $r['domain_verified_count'] ?? 0 }}</div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif

                <div class="mt-4 grid gap-3 md:grid-cols-2">
                    <div class="ops-metric text-xs">
                        <strong>Attempts by provider (24h)</strong>
                        <div class="mt-2 space-y-1">
                            @forelse (($providers['attempts_by_provider_24h'] ?? []) as $name => $count)
                                <div>{{ $name }}: {{ $count }}</div>
                            @empty
                                <div>none</div>
                            @endforelse
                        </div>
                    </div>
                    <div class="ops-metric text-xs">
                        <strong>Manual failover (24h)</strong>
                        <div class="mt-2 space-y-1">
                            @foreach (($providers['failover'] ?? []) as $label => $value)
                                <div>{{ str_replace('_', ' ', $label) }}: {{ $value }}</div>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>

            <div class="ops-card ops-card--info md:col-span-2">
                <h3 class="font-semibold">Manual provider retry</h3>
                <p class="ops-muted mt-1 text-xs">Only for messages with a safe, pre-acceptance failure. Ambiguous or already-accepted messages are always denied — see docs/OUTBOUND_PROVIDER_PORTABILITY.md.</p>
                <form wire:submit.prevent="retryWithProvider" class="mt-3 flex flex-wrap items-end gap-3">
                    <div>
                        <label class="block text-xs font-medium">Outbound message ID</label>
                        <input type="text" wire:model="retry_message_id" class="ops-input" placeholder="uuid">
                    </div>
                    <div>
                        <label class="block text-xs font-medium">Target provider</label>
                        <input type="text" wire:model="retry_provider" class="ops-input" placeholder="generic / ses">
                    </div>
                    <button type="submit" wire:confirm="Retry this message through the specified provider?" class="ops-btn">
                        Retry with provider
                    </button>
                </form>
            </div>

            <div class="ops-card {{ ($issues ?? []) === [] ? '' : 'ops-card--warn' }}">
                <h3 class="font-semibold">Issues</h3>
                <p class="mt-3 text-sm">{{ ($issues ?? []) === [] ? 'none' : implode(', ', $issues) }}</p>
            </div>
        </div>
    </div>
</x-filament-panels::page>
