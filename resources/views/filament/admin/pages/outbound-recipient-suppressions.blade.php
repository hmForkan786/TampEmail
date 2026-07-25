<x-filament-panels::page>
    <div class="space-y-6">
        <form wire:submit="addSuppression" class="rounded-xl border border-gray-200 p-4 dark:border-gray-700 space-y-3">
            <h3 class="text-sm font-semibold">Add manual suppression</h3>
            <div class="grid gap-3 md:grid-cols-3">
                <div>
                    <label class="text-xs">Email</label>
                    <input type="email" wire:model="email" class="fi-input block w-full rounded-lg border-gray-300" required />
                </div>
                <div>
                    <label class="text-xs">Reason</label>
                    <select wire:model="reason" class="fi-select block w-full rounded-lg border-gray-300">
                        <option value="manual">Manual</option>
                        <option value="policy">Policy</option>
                        <option value="invalid_recipient">Invalid recipient</option>
                    </select>
                </div>
                <div>
                    <label class="text-xs">Expires at (optional)</label>
                    <input type="datetime-local" wire:model="expires_at" class="fi-input block w-full rounded-lg border-gray-300" />
                </div>
            </div>
            <button type="submit" class="fi-btn fi-btn-color-primary rounded-lg px-3 py-2 text-sm text-white bg-primary-600">
                Suppress recipient
            </button>
            <p class="text-xs text-gray-500">Ordinary users cannot browse this list. Complaint/provider removals require platform admin elevation.</p>
        </form>

        <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-gray-700">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 dark:bg-gray-800">
                    <tr>
                        <th class="px-3 py-2 text-left">Recipient</th>
                        <th class="px-3 py-2 text-left">Reason</th>
                        <th class="px-3 py-2 text-left">Source</th>
                        <th class="px-3 py-2 text-left">Active</th>
                        <th class="px-3 py-2 text-left">Suppressed</th>
                        <th class="px-3 py-2 text-left"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($suppressions as $row)
                        <tr class="border-t border-gray-100 dark:border-gray-800">
                            <td class="px-3 py-2 font-mono">{{ $row['masked_recipient'] }}</td>
                            <td class="px-3 py-2">{{ $row['reason'] }}</td>
                            <td class="px-3 py-2">{{ $row['source'] }}</td>
                            <td class="px-3 py-2">{{ $row['active'] ? 'yes' : 'no' }}</td>
                            <td class="px-3 py-2">{{ $row['suppressed_at'] }}</td>
                            <td class="px-3 py-2 text-right">
                                @if ($row['active'])
                                    <button
                                        type="button"
                                        wire:click="removeSuppression('{{ $row['id'] }}')"
                                        wire:confirm="Remove this suppression?"
                                        class="text-danger-600 text-xs"
                                    >
                                        Remove
                                    </button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-3 py-6 text-center text-gray-500">No suppressions recorded.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-filament-panels::page>
