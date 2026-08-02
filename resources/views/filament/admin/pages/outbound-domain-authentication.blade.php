<x-filament-panels::page>
    <div class="temail-ops space-y-4">
        <div class="ops-alert ops-alert--info">
            SPF and DKIM are mandatory when provider DNS expectations are configured. Weak or missing DMARC is degraded (send allowed). This page never changes public DNS or exposes secrets.
        </div>

        @forelse ($rows as $row)
            @php
                $state = strtolower((string) ($row['state'] ?? ''));
                $cardTone = match (true) {
                    in_array($state, ['verified', 'ready', 'pass'], true) => '',
                    in_array($state, ['degraded', 'warning'], true) => 'ops-card--warn',
                    in_array($state, ['failed', 'invalid', 'blocked'], true) => 'ops-card--warn',
                    default => 'ops-card--info',
                };
                $chip = match (true) {
                    in_array($state, ['verified', 'ready', 'pass'], true) => 'ops-chip--ready',
                    in_array($state, ['degraded', 'warning'], true) => 'ops-chip--warn',
                    in_array($state, ['failed', 'invalid', 'blocked'], true) => 'ops-chip--danger',
                    default => 'ops-chip--info',
                };
            @endphp
            <div class="ops-card {{ $cardTone }}">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <div class="text-lg font-semibold" style="color: var(--farm-green)">{{ $row['domain'] }}</div>
                        <div class="ops-muted text-sm">provider={{ $row['provider'] }} · state={{ $row['state'] }}</div>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="ops-chip {{ $chip }}">{{ $row['state'] }}</span>
                        <button type="button" wire:click="recheck('{{ $row['id'] }}')" class="ops-btn ops-btn--info">
                            Recheck DNS
                        </button>
                    </div>
                </div>

                <div class="mt-3 grid gap-2 text-sm md:grid-cols-2">
                    <div>ownership: {{ $row['ownership'] }}</div>
                    <div>spf: {{ $row['spf'] }}</div>
                    <div>dkim: {{ $row['dkim'] }}</div>
                    <div>dmarc: {{ $row['dmarc'] }}</div>
                    <div>failure: {{ $row['failure_code'] ?: '—' }}</div>
                    <div>last check: {{ $row['last_checked_at'] ?: '—' }}</div>
                </div>

                <div class="mt-3 space-y-1 break-all font-mono text-xs" style="color: var(--brand-muted)">
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
            <p class="ops-muted text-sm">No outbound-enabled domains.</p>
        @endforelse
    </div>
</x-filament-panels::page>
