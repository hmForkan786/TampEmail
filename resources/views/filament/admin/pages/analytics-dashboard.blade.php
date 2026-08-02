<x-filament-panels::page>
    <div class="space-y-6">
        <div class="flex items-center justify-between gap-4">
            <div>
                <h2 class="text-lg font-semibold">Business overview</h2>
                <p class="mt-1 text-sm text-gray-500">
                    Range {{ $summary['range']['from'] ?? '—' }} → {{ $summary['range']['to'] ?? '—' }}
                    (platform rollups only; no PII)
                </p>
            </div>
            <button type="button" wire:click="refreshState" class="rounded-lg border px-3 py-2 text-sm">
                Refresh
            </button>
        </div>

        <div class="grid gap-4 md:grid-cols-5">
            @foreach ([
                'Revenue (minor)' => $summary['totals']['revenue_minor'] ?? 0,
                'Registrations' => $summary['totals']['users_registered'] ?? 0,
                'Mail received' => $summary['totals']['mail_received'] ?? 0,
                'Ad impressions' => $summary['totals']['ads_impressions'] ?? 0,
                'API requests' => $summary['totals']['api_requests'] ?? 0,
            ] as $label => $value)
                <div class="rounded-xl border p-4">
                    <p class="text-xs text-gray-500">{{ $label }}</p>
                    <p class="text-2xl font-semibold">{{ number_format((float) $value) }}</p>
                </div>
            @endforeach
        </div>

        @php
            $domains = $summary['domains'] ?? [];
        @endphp

        <div class="grid gap-4 lg:grid-cols-2">
            @foreach ($domains as $domain => $metrics)
                <div class="rounded-xl border p-4">
                    <h3 class="font-semibold capitalize">{{ $domain }}</h3>
                    <dl class="mt-3 grid grid-cols-2 gap-2 text-sm">
                        @foreach ($metrics as $key => $val)
                            <div class="flex justify-between gap-2 border-b border-gray-100 py-1 dark:border-gray-800">
                                <dt class="text-gray-500">{{ $key }}</dt>
                                <dd class="font-medium">{{ number_format((float) $val, 2) }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </div>
            @endforeach
        </div>

        <div class="rounded-xl border p-4">
            <h3 class="font-semibold">Trends ({{ count($trends['labels'] ?? []) }} days)</h3>
            @php
                $labels = $trends['labels'] ?? [];
                $seriesMap = [
                    'users_registrations' => 'User growth',
                    'billing_revenue_minor' => 'Revenue',
                    'inbox_created' => 'Inbox created',
                    'email_received' => 'Mail volume',
                    'affiliate_conversions' => 'Affiliate growth',
                    'ads_revenue_minor' => 'Ads revenue',
                ];
            @endphp
            <div class="mt-4 space-y-6">
                @foreach ($seriesMap as $key => $title)
                    @php
                        $points = $trends['series'][$key] ?? [];
                        $max = max(1, ...(array_map('floatval', $points) ?: [1]));
                    @endphp
                    <div>
                        <p class="mb-2 text-sm font-medium">{{ $title }}</p>
                        <div class="flex h-24 items-end gap-px overflow-x-auto">
                            @foreach ($points as $i => $point)
                                @php $h = (int) max(2, round(((float) $point / $max) * 100)); @endphp
                                <div
                                    class="min-w-[4px] flex-1 rounded-t bg-primary-500/80"
                                    style="height: {{ $h }}%"
                                    title="{{ ($labels[$i] ?? '') }}: {{ number_format((float) $point, 2) }}"
                                ></div>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</x-filament-panels::page>
