<div class="space-y-6">
    <x-filament::section>
        <x-slot name="heading">Settings health</x-slot>
        <ul class="space-y-2 text-sm">
            @foreach ($health['checks'] ?? [] as $check)
                <li>
                    <strong>{{ $check['ok'] ? 'OK' : 'FAIL' }}</strong>
                    — {{ $check['name'] }}: {{ $check['detail'] }}
                </li>
            @endforeach
        </ul>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Recent users (masked summary)</x-slot>
        <p class="text-sm text-gray-500 mb-4">Read-only. API key secrets are never available here.</p>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr>
                        <th class="text-left p-2">Email</th>
                        <th class="text-left p-2">Status</th>
                        <th class="text-left p-2">Verified</th>
                        <th class="text-left p-2">Sessions</th>
                        <th class="text-left p-2">API keys</th>
                        <th class="text-left p-2">Notif prefs</th>
                        <th class="text-left p-2">Billing email</th>
                        <th class="text-left p-2">Export</th>
                        <th class="text-left p-2">Closed</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($recentUsers as $row)
                        <tr>
                            <td class="p-2">{{ $row['email'] }}</td>
                            <td class="p-2">{{ $row['status'] }}</td>
                            <td class="p-2">{{ $row['verified'] ? 'yes' : 'no' }}</td>
                            <td class="p-2">{{ $row['sessions'] }}</td>
                            <td class="p-2">{{ $row['api_keys'] }}</td>
                            <td class="p-2">{{ $row['notification_prefs'] }}</td>
                            <td class="p-2">{{ $row['billing_email'] }}</td>
                            <td class="p-2">{{ $row['latest_export_status'] ?? 'n/a' }}</td>
                            <td class="p-2">{{ $row['closure'] ?? 'n/a' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-filament::section>
</div>
