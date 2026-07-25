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

            <div class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 md:col-span-2">
                <h3 class="font-semibold">Provider portability</h3>
                <p class="mt-1 text-xs text-gray-500">Automatic cross-provider failover is not implemented — see docs/OUTBOUND_PROVIDER_PORTABILITY.md. Manual retry below is the only cross-provider path.</p>
                <dl class="mt-3 space-y-2 text-sm">
                    <div>Primary provider: {{ $providers['primary_provider'] ?? 'unknown' }}</div>
                    <div>Secondary provider: {{ $providers['secondary_provider'] ?? 'none configured' }}</div>
                    <div>Failover enabled (defense-in-depth flag only): {{ !empty($providers['failover_enabled']) ? 'Yes' : 'No' }}</div>
                    <div>Configuration errors: {{ empty($providers['config_errors']) ? 'none' : implode(', ', $providers['config_errors']) }}</div>
                </dl>

                @if (!empty($providers['readiness']))
                    <div class="mt-4 grid gap-3 md:grid-cols-2">
                        @foreach ($providers['readiness'] as $name => $r)
                            <div class="rounded-lg bg-gray-50 p-3 text-xs dark:bg-gray-800">
                                <div class="font-semibold">{{ $name }}</div>
                                <div>Ready: {{ !empty($r['ready']) ? 'Yes' : 'No' }}</div>
                                <div>Parser resolves: {{ !empty($r['parser_resolves']) ? 'Yes' : 'No' }}</div>
                                <div>Webhook secret present: {{ !empty($r['webhook_secret_present']) ? 'Yes' : 'No' }}</div>
                                <div>Domain verified count: {{ $r['domain_verified_count'] ?? 0 }}</div>
                            </div>
                        @endforeach
                    </div>
                @endif

                <div class="mt-4 grid gap-3 md:grid-cols-2">
                    <div class="rounded-lg bg-gray-50 p-3 text-xs dark:bg-gray-800">
                        <div class="font-semibold">Attempts by provider (24h)</div>
                        @forelse (($providers['attempts_by_provider_24h'] ?? []) as $name => $count)
                            <div>{{ $name }}: {{ $count }}</div>
                        @empty
                            <div>none</div>
                        @endforelse
                    </div>
                    <div class="rounded-lg bg-gray-50 p-3 text-xs dark:bg-gray-800">
                        <div class="font-semibold">Manual failover (24h)</div>
                        @foreach (($providers['failover'] ?? []) as $label => $value)
                            <div>{{ str_replace('_', ' ', $label) }}: {{ $value }}</div>
                        @endforeach
                    </div>
                </div>
            </div>

            <div class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 md:col-span-2">
                <h3 class="font-semibold">Manual provider retry</h3>
                <p class="mt-1 text-xs text-gray-500">Only for messages with a safe, pre-acceptance failure. Ambiguous or already-accepted messages are always denied — see docs/OUTBOUND_PROVIDER_PORTABILITY.md.</p>
                <form wire:submit.prevent="retryWithProvider" class="mt-3 flex flex-wrap items-end gap-3">
                    <div>
                        <label class="block text-xs font-medium">Outbound message ID</label>
                        <input type="text" wire:model="retry_message_id" class="mt-1 rounded-md border-gray-300 text-sm dark:bg-gray-800" placeholder="uuid">
                    </div>
                    <div>
                        <label class="block text-xs font-medium">Target provider</label>
                        <input type="text" wire:model="retry_provider" class="mt-1 rounded-md border-gray-300 text-sm dark:bg-gray-800" placeholder="generic / ses">
                    </div>
                    <button type="submit" wire:confirm="Retry this message through the specified provider?" class="rounded-md bg-primary-600 px-3 py-2 text-sm font-medium text-white">
                        Retry with provider
                    </button>
                </form>
            </div>

            <div class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <h3 class="font-semibold">Issues</h3>
                <p class="mt-3 text-sm">{{ ($issues ?? []) === [] ? 'none' : implode(', ', $issues) }}</p>
            </div>
        </div>
    </div>
</x-filament-panels::page>
