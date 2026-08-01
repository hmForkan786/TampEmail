<div class="space-y-6">
    <x-filament::section>
        <x-slot name="heading">Registration settings (read-only)</x-slot>
        <x-slot name="description">Runtime mutation is env/config-driven. No unsafe generic settings table.</x-slot>
        <dl class="grid grid-cols-1 gap-3 md:grid-cols-2 text-sm">
            <div><dt class="font-medium">Mode</dt><dd>{{ $settings['registration_mode_label'] ?? '' }} ({{ $settings['registration_mode'] ?? '' }})</dd></div>
            <div><dt class="font-medium">Verification required</dt><dd>{{ !empty($settings['verification_required']) ? 'yes' : 'no' }}</dd></div>
            <div><dt class="font-medium">CAPTCHA/challenge</dt><dd>{{ !empty($settings['challenge_enabled']) ? 'enabled' : 'disabled' }}</dd></div>
            <div><dt class="font-medium">Session limit</dt><dd>{{ $settings['max_active_web_sessions'] ?? 0 }} (0=unlimited)</dd></div>
            <div><dt class="font-medium">Closure grace days</dt><dd>{{ $settings['closure_grace_days'] ?? 0 }}</dd></div>
            <div><dt class="font-medium">Pending users</dt><dd>{{ $settings['pending_users'] ?? 0 }}</dd></div>
            <div><dt class="font-medium">Open invites</dt><dd>{{ $settings['open_invites'] ?? 0 }}</dd></div>
            <div><dt class="font-medium">Password policy</dt><dd><pre class="text-xs">{{ json_encode($settings['password_policy'] ?? [], JSON_PRETTY_PRINT) }}</pre></dd></div>
        </dl>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Health</x-slot>
        <ul class="text-sm space-y-1">
            @foreach (($health['checks'] ?? []) as $check)
                <li>{{ ($check['ok'] ?? false) ? 'OK' : 'FAIL' }} — {{ $check['name'] ?? '' }}: {{ $check['detail'] ?? '' }}</li>
            @endforeach
        </ul>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Recovery requests</x-slot>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left">
                        <th class="p-2">ID</th>
                        <th class="p-2">Status</th>
                        <th class="p-2">Reason</th>
                        <th class="p-2">User</th>
                        <th class="p-2">Created</th>
                        <th class="p-2">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($pendingRecoveries as $row)
                        <tr class="border-t">
                            <td class="p-2 font-mono text-xs">{{ \Illuminate\Support\Str::limit($row['id'], 8, '') }}</td>
                            <td class="p-2">{{ $row['status'] }}</td>
                            <td class="p-2">{{ $row['reason'] }}</td>
                            <td class="p-2 font-mono text-xs">{{ $row['user_id'] ? \Illuminate\Support\Str::limit($row['user_id'], 8, '') : '—' }}</td>
                            <td class="p-2">{{ $row['created_at'] }}</td>
                            <td class="p-2 space-x-1">
                                <x-filament::button size="xs" wire:click="startReview('{{ $row['id'] }}')">Review</x-filament::button>
                                <x-filament::button size="xs" color="success" wire:click="approveRecovery('{{ $row['id'] }}')">Approve</x-filament::button>
                                <x-filament::button size="xs" color="danger" wire:click="rejectRecovery('{{ $row['id'] }}')">Reject</x-filament::button>
                                <x-filament::button size="xs" color="warning" wire:click="completeRecovery('{{ $row['id'] }}')">Complete</x-filament::button>
                                @if ($row['user_id'])
                                    <x-filament::button size="xs" color="gray" wire:click="forceRevokeSessions('{{ $row['user_id'] }}')">Revoke sessions</x-filament::button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td class="p-2" colspan="6">No recovery requests.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Recent login history (hashed)</x-slot>
        <ul class="text-sm space-y-1">
            @forelse ($recentLogins as $login)
                <li>
                    {{ !empty($login['success']) ? 'success' : 'failure' }}
                    @if (!empty($login['failure_reason_code'])) — {{ $login['failure_reason_code'] }} @endif
                    · {{ $login['occurred_at'] ?? '' }}
                </li>
            @empty
                <li>No login attempts recorded.</li>
            @endforelse
        </ul>
    </x-filament::section>
</div>
