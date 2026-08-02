<x-filament-panels::page>
    <div class="temail-ops space-y-6">
        <form wire:submit="addSuppression" class="ops-card ops-card--warn space-y-3">
            <p class="ops-kicker">Recipient controls</p>
            <h3 class="text-sm font-semibold">Add manual suppression</h3>
            <div class="grid gap-3 md:grid-cols-3">
                <div>
                    <label class="text-xs font-semibold">Email</label>
                    <input type="email" wire:model="email" class="ops-input" required />
                </div>
                <div>
                    <label class="text-xs font-semibold">Reason</label>
                    <select wire:model="reason" class="ops-input">
                        <option value="manual">Manual</option>
                        <option value="policy">Policy</option>
                        <option value="invalid_recipient">Invalid recipient</option>
                    </select>
                </div>
                <div>
                    <label class="text-xs font-semibold">Expires at (optional)</label>
                    <input type="datetime-local" wire:model="expires_at" class="ops-input" />
                </div>
            </div>
            <button type="submit" class="ops-btn ops-btn--warm">
                Suppress recipient
            </button>
            <p class="ops-muted text-xs">Ordinary users cannot browse this list. Complaint/provider removals require platform admin elevation.</p>
        </form>

        <div class="ops-card overflow-x-auto p-0">
            <table class="ops-table">
                <thead>
                    <tr>
                        <th class="px-3">Recipient</th>
                        <th class="px-3">Reason</th>
                        <th class="px-3">Source</th>
                        <th class="px-3">Active</th>
                        <th class="px-3">Suppressed</th>
                        <th class="px-3"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($suppressions as $row)
                        <tr>
                            <td class="px-3 font-mono">{{ $row['masked_recipient'] }}</td>
                            <td class="px-3">{{ $row['reason'] }}</td>
                            <td class="px-3">{{ $row['source'] }}</td>
                            <td class="px-3">
                                <span class="ops-chip {{ $row['active'] ? 'ops-chip--caution' : 'ops-chip--ok' }}">
                                    {{ $row['active'] ? 'yes' : 'no' }}
                                </span>
                            </td>
                            <td class="px-3">{{ $row['suppressed_at'] }}</td>
                            <td class="px-3 text-right">
                                @if ($row['active'])
                                    <button
                                        type="button"
                                        wire:click="removeSuppression('{{ $row['id'] }}')"
                                        wire:confirm="Remove this suppression?"
                                        class="ops-link ops-link--danger text-xs"
                                    >
                                        Remove
                                    </button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="ops-muted px-3 py-6 text-center">No suppressions recorded.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-filament-panels::page>
