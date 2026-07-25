<x-filament-panels::page>
    <div class="space-y-6">
        <div class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="flex items-center justify-between">
                <h2 class="text-lg font-semibold">Launch readiness</h2>
                <span class="rounded-full px-3 py-1 text-sm font-medium">{{ $readiness['status'] ?? 'unknown' }}</span>
            </div>
            <p class="mt-2 text-sm text-gray-500">Last evaluated: {{ $readiness['evaluated_at'] ?? 'unknown' }}</p>
            <p class="mt-1 text-sm text-gray-500">This page never sends a test email. Use `outbound:canary-send` explicitly for that.</p>
            @if (!empty($readiness['reasons']))
                <p class="mt-2 text-sm">Reasons: {{ implode(', ', $readiness['reasons']) }}</p>
            @endif
            @if ($has_overrides)
                <p class="mt-2 text-xs text-amber-600">Live overrides are active (differ from env defaults).</p>
            @endif
        </div>

        <div class="grid gap-6 md:grid-cols-2">
            <form wire:submit="updateRollout" class="rounded-xl border border-gray-200 p-4 dark:border-gray-700 space-y-3">
                <h3 class="text-sm font-semibold">Rollout controls</h3>
                <div class="grid gap-3 md:grid-cols-2">
                    <div>
                        <label class="text-xs">Rollout mode</label>
                        <select wire:model="rollout_mode" class="fi-select block w-full rounded-lg border-gray-300">
                            <option value="disabled">Disabled</option>
                            <option value="canary">Canary</option>
                            <option value="percentage">Percentage</option>
                            <option value="enabled">Enabled</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-xs">Rollout percent</label>
                        <input type="number" min="0" max="100" wire:model="rollout_percent" class="fi-input block w-full rounded-lg border-gray-300" />
                    </div>
                </div>
                <button
                    type="submit"
                    wire:confirm="Change the live outbound rollout mode/percent?"
                    class="fi-btn fi-btn-color-primary rounded-lg px-3 py-2 text-sm text-white bg-primary-600"
                >
                    Update rollout
                </button>
            </form>

            <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700 space-y-3">
                <h3 class="text-sm font-semibold">Emergency stop</h3>
                <p class="text-xs text-gray-500">Overrides every enablement, including canaries and a 100% rollout. Never deletes queued messages or marks them failed.</p>
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" wire:model="emergency_stop" />
                    Emergency stop engaged
                </label>
                <button
                    type="button"
                    wire:click="toggleEmergencyStop"
                    wire:confirm="Change the live outbound emergency stop state?"
                    class="fi-btn fi-btn-color-danger rounded-lg px-3 py-2 text-sm text-white bg-danger-600"
                >
                    Apply emergency stop state
                </button>
                <button
                    type="button"
                    wire:click="clearOverrides"
                    wire:confirm="Clear all live rollout overrides and revert to env defaults?"
                    class="text-xs text-gray-500 underline"
                >
                    Clear live overrides
                </button>
            </div>
        </div>

        <form wire:submit="addCanary" class="rounded-xl border border-gray-200 p-4 dark:border-gray-700 space-y-3">
            <h3 class="text-sm font-semibold">Add canary</h3>
            <div class="grid gap-3 md:grid-cols-3">
                <div>
                    <label class="text-xs">Subject type</label>
                    <select wire:model="canary_subject_type" class="fi-select block w-full rounded-lg border-gray-300">
                        <option value="user">User</option>
                        <option value="inbox">Inbox</option>
                        <option value="domain">Domain</option>
                        <option value="api_key">API key</option>
                    </select>
                </div>
                <div>
                    <label class="text-xs">Subject ID (UUID)</label>
                    <input type="text" wire:model="canary_subject_id" class="fi-input block w-full rounded-lg border-gray-300" />
                </div>
                <div>
                    <label class="text-xs">Label (optional)</label>
                    <input type="text" wire:model="canary_label" class="fi-input block w-full rounded-lg border-gray-300" />
                </div>
            </div>
            <button type="submit" class="fi-btn fi-btn-color-primary rounded-lg px-3 py-2 text-sm text-white bg-primary-600">
                Add canary
            </button>
        </form>

        <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-gray-700">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 dark:bg-gray-800">
                    <tr>
                        <th class="px-3 py-2 text-left">Type</th>
                        <th class="px-3 py-2 text-left">Subject ID</th>
                        <th class="px-3 py-2 text-left">Label</th>
                        <th class="px-3 py-2 text-left">Added</th>
                        <th class="px-3 py-2 text-left"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($canaries as $row)
                        <tr class="border-t border-gray-100 dark:border-gray-800">
                            <td class="px-3 py-2">{{ $row['subject_type'] }}</td>
                            <td class="px-3 py-2 font-mono">{{ $row['subject_id'] }}</td>
                            <td class="px-3 py-2">{{ $row['label'] ?? '—' }}</td>
                            <td class="px-3 py-2">{{ $row['added_at'] }}</td>
                            <td class="px-3 py-2 text-right">
                                <button
                                    type="button"
                                    wire:click="removeCanary('{{ $row['id'] }}')"
                                    wire:confirm="Remove this canary?"
                                    class="text-danger-600 text-xs"
                                >
                                    Remove
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-3 py-6 text-center text-gray-500">No active canaries.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="grid gap-6 md:grid-cols-2">
            <div class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <h3 class="font-semibold">Readiness checks</h3>
                <dl class="mt-3 space-y-2 text-sm">
                    @foreach (($readiness['checks'] ?? []) as $label => $value)
                        <div>{{ str_replace('_', ' ', $label) }}: {{ is_array($value) ? json_encode($value) : $value }}</div>
                    @endforeach
                </dl>
            </div>

            <div class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <div class="flex items-center justify-between">
                    <h3 class="font-semibold">Pause recommendation</h3>
                    <span class="rounded-full px-3 py-1 text-sm font-medium">{{ $recommendation['recommendation'] ?? 'unknown' }}</span>
                </div>
                <p class="mt-2 text-xs text-gray-500">Advisory only — nothing here auto-disables outbound.</p>
                @if (!empty($recommendation['reasons']))
                    <p class="mt-2 text-sm">Reasons: {{ implode(', ', $recommendation['reasons']) }}</p>
                @endif
                <dl class="mt-3 space-y-2 text-sm">
                    @foreach (($recommendation['metrics'] ?? []) as $label => $value)
                        <div>{{ str_replace('_', ' ', $label) }}: {{ is_array($value) ? json_encode($value) : $value }}</div>
                    @endforeach
                </dl>
            </div>
        </div>
    </div>
</x-filament-panels::page>
