<x-filament-panels::page>
    <div class="space-y-4">
        <div class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="flex items-center justify-between gap-4">
                <div>
                    <h2 class="text-lg font-semibold">Pending manual reviews</h2>
                    <p class="mt-1 text-sm text-gray-500">Fraud signals routed to manual review, most recent first.</p>
                </div>
                <button type="button" wire:click="refreshState" class="rounded-lg border px-3 py-2 text-sm">
                    Refresh
                </button>
            </div>

            <div class="mt-4 overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="border-b text-xs text-gray-500">
                            <th class="py-2 pr-4">Affiliate</th>
                            <th class="py-2 pr-4">Reason codes</th>
                            <th class="py-2 pr-4">Referred user</th>
                            <th class="py-2 pr-4">Created</th>
                            <th class="py-2 pr-4"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($flags as $flag)
                            <tr class="border-b last:border-0">
                                <td class="py-2 pr-4">{{ $flag->profile?->affiliate_code ?? '—' }}</td>
                                <td class="py-2 pr-4">{{ implode(', ', $flag->reason_codes ?? []) ?: '—' }}</td>
                                <td class="py-2 pr-4">{{ $flag->referred_user_id ?? '—' }}</td>
                                <td class="py-2 pr-4">{{ $flag->created_at?->toDayDateTimeString() ?? '—' }}</td>
                                <td class="py-2 pr-4">
                                    <button
                                        type="button"
                                        wire:click="markReviewed('{{ $flag->getKey() }}')"
                                        wire:confirm="Mark this fraud flag as reviewed?"
                                        class="rounded-lg border px-3 py-1 text-xs"
                                    >
                                        Mark reviewed
                                    </button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="py-6 text-center text-sm text-gray-500">No flags awaiting manual review.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-filament-panels::page>
