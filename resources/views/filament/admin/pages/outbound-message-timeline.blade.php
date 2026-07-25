<x-filament-panels::page>
    <div class="space-y-6">
        <div class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <form wire:submit.prevent="loadTimeline" class="flex items-end gap-3">
                <div class="flex-1">
                    <label for="message_id" class="text-sm font-medium">Outbound message ID</label>
                    <input
                        type="text"
                        id="message_id"
                        wire:model="message_id"
                        placeholder="00000000-0000-0000-0000-000000000000"
                        class="mt-1 block w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800"
                    />
                </div>
                <button type="submit" class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white">
                    Load timeline
                </button>
            </form>
            @if ($lookupError)
                <p class="mt-3 text-sm text-danger-600">{{ $lookupError }}</p>
            @endif
        </div>

        @if ($summary)
            <div class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <h3 class="font-semibold">Message summary</h3>
                <dl class="mt-3 grid gap-2 text-sm md:grid-cols-3">
                    <div>ID: {{ $summary['id'] }}</div>
                    <div>Operation: {{ $summary['operation'] }}</div>
                    <div>State: {{ $summary['state'] }}</div>
                    <div>Attempts: {{ $summary['attempt_count'] }}</div>
                    <div>Provider: {{ $summary['provider'] ?? 'none' }}</div>
                    <div>Reconciliation note: {{ $summary['reconciliation_note'] ?? 'none' }}</div>
                    <div>Created: {{ $summary['created_at'] ?? 'unknown' }}</div>
                </dl>
            </div>

            <div class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <h3 class="font-semibold">Timeline</h3>
                @if ($timeline === [])
                    <p class="mt-3 text-sm text-gray-500">No timeline events recorded.</p>
                @else
                    <ol class="mt-3 space-y-3 text-sm">
                        @foreach ($timeline as $entry)
                            <li class="border-b border-gray-100 pb-2 dark:border-white/10">
                                <div class="flex items-center justify-between">
                                    <span class="font-medium">{{ $entry['label'] }}</span>
                                    <span class="text-gray-500">{{ $entry['occurred_at'] ?? 'unknown' }}</span>
                                </div>
                                <div class="mt-1 text-gray-500">
                                    Type: {{ $entry['type'] }}
                                    &middot; Actor: {{ $entry['actor_type'] }}
                                    @if (!empty($entry['attempt_number']))
                                        &middot; Attempt: {{ $entry['attempt_number'] }}
                                    @endif
                                    @if (!empty($entry['provider']))
                                        &middot; Provider: {{ $entry['provider'] }}
                                    @endif
                                    @if (!empty($entry['category']))
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
