<x-filament-panels::page>
    <div class="space-y-4">
        <p class="text-sm text-gray-600 dark:text-gray-300">
            SPF and DKIM are mandatory when provider DNS expectations are configured. Weak or missing DMARC is degraded (send allowed). This page never changes public DNS or exposes secrets.
        </p>

        @forelse ($rows as $row)
            <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <div class="text-lg font-semibold">{{ $row['domain'] }}</div>
                        <div class="text-sm text-gray-500">provider={{ $row['provider'] }} · state={{ $row['state'] }}</div>
                    </div>
                    <x-filament::button wire:click="recheck('{{ $row['id'] }}')" size="sm" color="gray">
                        Recheck DNS
                    </x-filament::button>
                </div>

                <div class="mt-3 grid gap-2 text-sm md:grid-cols-2">
                    <div>ownership: {{ $row['ownership'] }}</div>
                    <div>spf: {{ $row['spf'] }}</div>
                    <div>dkim: {{ $row['dkim'] }}</div>
                    <div>dmarc: {{ $row['dmarc'] }}</div>
                    <div>failure: {{ $row['failure_code'] ?: '—' }}</div>
                    <div>last check: {{ $row['last_checked_at'] ?: '—' }}</div>
                </div>

                <div class="mt-3 space-y-1 text-xs font-mono break-all text-gray-700 dark:text-gray-200">
                    @if ($row['expected_ownership'])
                        <div>TXT @ {{ $row['domain'] }} → {{ $row['expected_ownership'] }}</div>
                    @endif
                    @if ($row['expected_spf'])
                        <div>TXT @ {{ $row['domain'] }} → {{ $row['expected_spf'] }}</div>
                    @endif
                    @foreach ($row['expected_dkim'] as $dkim)
                        <div>{{ $dkim['type'] }} {{ $dkim['host'] }} → {{ $dkim['value'] }}</div>
                    @endforeach
                    @if ($row['expected_dmarc'])
                        <div>TXT _dmarc.{{ $row['domain'] }} → {{ $row['expected_dmarc'] }}</div>
                    @endif
                </div>
            </div>
        @empty
            <p class="text-sm text-gray-500">No outbound-enabled domains.</p>
        @endforelse
    </div>
</x-filament-panels::page>
