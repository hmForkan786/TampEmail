<x-filament-panels::page>
    <div class="temail-ops space-y-6">
        <div class="ops-card ops-card--hero">
            <p class="ops-kicker">Delivery forensics</p>
            <form wire:submit.prevent="loadTimeline" class="mt-3 flex flex-wrap items-end gap-3">
                <div class="min-w-[16rem] flex-1">
                    <label for="message_id" class="text-sm font-semibold">Outbound message ID</label>
                    <input
                        type="text"
                        id="message_id"
                        wire:model="message_id"
                        placeholder="00000000-0000-0000-0000-000000000000"
                        class="ops-input"
                    />
                </div>
                <button type="submit" class="ops-btn">
                    Load timeline
                </button>
            </form>
            @if ($lookupError)
                <div class="ops-alert ops-alert--danger mt-3 text-sm">{{ $lookupError }}</div>
            @endif
        </div>

        @if ($summary)
            <div class="ops-card ops-card--info">
                <h3 class="font-semibold">Message summary</h3>
                <dl class="mt-3 grid gap-2 text-sm md:grid-cols-3">
                    <div>ID: {{ $summary['id'] }}</div>
                    <div>Operation: {{ $summary['operation'] }}</div>
                    <div>
                        State:
                        <span class="ops-chip ops-chip--highlight">{{ $summary['state'] }}</span>
                    </div>
                    <div>Attempts: {{ $summary['attempt_count'] }}</div>
                    <div>Provider: {{ $summary['provider'] ?? 'none' }}</div>
                    <div>Reconciliation note: {{ $summary['reconciliation_note'] ?? 'none' }}</div>
                    <div>Created: {{ $summary['created_at'] ?? 'unknown' }}</div>
                </dl>
            </div>

            <div class="ops-card">
                <h3 class="font-semibold">Timeline</h3>
                @if ($timeline === [])
                    <p class="ops-muted mt-3 text-sm">No timeline events recorded.</p>
                @else
                    <ol class="mt-3 space-y-3 text-sm">
                        @foreach ($timeline as $entry)
                            <li class="ops-metric">
                                <div class="flex items-center justify-between gap-3">
                                    <strong>{{ $entry['label'] }}</strong>
                                    <span class="ops-chip ops-chip--accent">{{ $entry['occurred_at'] ?? 'unknown' }}</span>
                                </div>
                                <div class="ops-muted mt-1">
                                    Type: {{ $entry['type'] }}
                                    &middot; Actor: {{ $entry['actor_type'] }}
                                    @if (! empty($entry['attempt_number']))
                                        &middot; Attempt: {{ $entry['attempt_number'] }}
                                    @endif
                                    @if (! empty($entry['provider']))
                                        &middot; Provider: {{ $entry['provider'] }}
                                    @endif
                                    @if (! empty($entry['category']))
                                        &middot; Category: {{ $entry['category'] }}
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ol>
                @endif
            </div>
        @endif
    </div>
</x-filament-panels::page>
