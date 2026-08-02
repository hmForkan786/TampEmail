<x-filament-panels::page>
    <style>
        .launch-control { max-width:1180px; margin:0 auto; color:var(--brand-ink); }
        .launch-control .launch-section { margin: 0 0 2rem; }
        .launch-control .launch-kicker { margin:0; color:var(--farm-green); font-size:.72rem; font-weight:800; letter-spacing:.12em; text-transform:uppercase; }
        .launch-control .launch-section-title { margin: .3rem 0 0; font-size: 1.3rem; font-weight: 800; color:var(--farm-green); }
        .launch-control .launch-section-copy { margin: .35rem 0 1rem; color: var(--brand-muted); font-size: .92rem; }
        .launch-control .launch-card { border:1px solid var(--brand-line); border-radius:16px; background:#fff; padding:1.5rem; box-shadow:0 4px 18px var(--brand-glow); }
        .launch-control .launch-hero { border-color:var(--lemon-green); background:linear-gradient(135deg,var(--light-green),#fff 64%); }
        .launch-control .launch-grid { display: grid; gap: 1rem; }
        .launch-control .launch-grid--checks { grid-template-columns: repeat(4, minmax(0,1fr)); }
        .launch-control .launch-grid--controls { grid-template-columns: repeat(2, minmax(0,1fr)); gap: 1.5rem; }
        .launch-control .launch-grid--guidance { grid-template-columns: 1.4fr 1fr; gap: 1.5rem; }
        .launch-control .launch-check { padding:1rem; border:1px solid #cde8c9; border-radius:12px; background:linear-gradient(135deg,#fff,var(--light-green)); }
        .launch-control .launch-check strong { display:block; font-size:.9rem; color:var(--farm-green); }
        .launch-control .launch-check small {display:block;margin-top:.4rem;color:var(--ceramic-green);}
        .launch-control .launch-check--bad { border-color:#f2c1cd; background:#fff7f9; }
        .launch-control .launch-check--bad small { color:var(--chilli); }
        .launch-control .launch-label { display:block; color:#344054; font-size:.86rem; font-weight:700; }
        .launch-control input,.launch-control select { width:100%; margin-top:.45rem; padding:.7rem .75rem; border:1px solid #b7d0bf; border-radius:8px; background:#fff; color:#182230; }
        .launch-control .launch-fields { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:1rem; margin-top:1.2rem; }
        .launch-control .launch-fields--three { grid-template-columns:repeat(3,minmax(0,1fr)); }
        .launch-control .launch-button { display:inline-flex; align-items:center; justify-content:center; margin-top:1.2rem; padding:.7rem 1rem; border:0; border-radius:8px; background:var(--farm-green); color:#fff; font-size:.87rem; font-weight:700; cursor:pointer; }
        .launch-control .launch-button:hover { background:var(--lettuce); }
        .launch-control .launch-button--danger { background:var(--chilli); }
        .launch-control .launch-button--danger:hover { background:var(--pumping-spice); }
        .launch-control .launch-button--warm { background:var(--pumpkin); }
        .launch-control .launch-button--warm:hover { background:var(--apricot); }
        .launch-control .launch-link { margin-left:1rem; color:var(--lynx-blue); font-size:.85rem; text-decoration:underline; cursor:pointer; }
        .launch-control .launch-alert { margin-top:1rem; padding:1rem; border-radius:10px; background:linear-gradient(135deg,#fff9df,#fff); border:1px solid var(--yellow-jacket); color:#735900; }
        .launch-control .launch-chip { display:inline-block; margin:.45rem .35rem 0 0; padding:.3rem .55rem; border-radius:999px; background:var(--lemon); border:1px solid var(--yellow-jacket); font-size:.76rem; color:#5c4700; font-weight:700; }
        .launch-control .launch-chip--accent { background:rgba(128,109,198,.16); border-color:rgba(128,109,198,.4); color:#4f3d8f; }
        .launch-control .launch-chip--info { background:rgba(38,173,228,.16); border-color:rgba(38,173,228,.4); color:#0b6f99; }
        .launch-control .launch-table { width:100%; margin-top:1.1rem; border-collapse:collapse; font-size:.85rem; }
        .launch-control .launch-table th,.launch-control .launch-table td { padding:.75rem .4rem; border-bottom:1px solid #e6ebf1; text-align:left; }
        .launch-control .launch-table th { color:var(--farm-green); font-size:.72rem; text-transform:uppercase; background:rgba(225,239,163,.35); }
        .launch-control .launch-table td:last-child { text-align:right; }
        .launch-control .launch-status { display:inline-block; padding:.35rem .65rem; border-radius:999px; background:var(--lemon); color:#4d3e00; font-weight:800; text-transform:capitalize; }
        .launch-control details { margin-top:1rem; padding:.8rem; border:1px solid #e2e8f0; border-radius:8px; }
        .launch-control summary { cursor:pointer; font-weight:700; color:var(--true-v); }
        @media (max-width: 900px) {
            .launch-control .launch-grid--checks { grid-template-columns:repeat(2,minmax(0,1fr)); }
            .launch-control .launch-grid--controls,.launch-control .launch-grid--guidance { grid-template-columns:1fr; }
            .launch-control .launch-fields--three { grid-template-columns:1fr; }
        }
        @media (max-width: 560px) {
            .launch-control .launch-grid--checks,.launch-control .launch-fields { grid-template-columns:1fr; }
            .launch-control .launch-card { padding:1rem; }
        }
    </style>
    @php
        $status = strtolower((string) ($readiness['status'] ?? 'unknown'));
        $statusColors = ['ready' => 'success', 'degraded' => 'warning', 'disabled' => 'gray', 'blocked' => 'danger'];
        $statusColor = $statusColors[$status] ?? 'gray';
        $checks = (array) ($readiness['checks'] ?? []);
        $checkCards = [
            'Migrations' => (bool) data_get($checks, 'migrations.complete', false),
            'Feature flags' => (bool) data_get($checks, 'feature_flags.outbound_enabled', false),
            'Transport' => (bool) data_get($checks, 'transport.valid', false),
            'Queue topology' => in_array(data_get($checks, 'queue.status'), ['ready', 'degraded'], true),
            'Provider parser' => (bool) data_get($checks, 'provider.parser_resolves', false),
            'Verified domain' => (int) data_get($checks, 'domain.verified_count', 0) > 0,
            'Plan features' => (bool) data_get($checks, 'plan_features.all_present', false),
        ];
    @endphp

    <div class="launch-control temail-ops">
        <section aria-labelledby="launch-status-heading" class="launch-section">
            <div>
                <p class="launch-kicker">Section 1</p>
                <h2 id="launch-status-heading" class="launch-section-title">Launch status</h2>
                <p class="launch-section-copy">See whether outbound is safe to enable and what needs attention first.</p>
            </div>
            <div class="launch-card launch-hero">
                <div class="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                        <div class="flex items-center gap-3">
                            <div class="flex h-11 w-11 items-center justify-center rounded-xl text-xl" style="background:rgba(145,204,59,.25);color:var(--farm-green)">↗</div>
                            <div>
                                <p class="text-sm font-medium" style="color:var(--brand-muted)">Outbound delivery</p>
                                <h2 class="text-2xl font-bold tracking-tight" style="color:var(--farm-green)">Launch control room</h2>
                            </div>
                        </div>
                        <p class="mt-4 max-w-2xl text-sm" style="color:var(--brand-muted)">Review readiness, make a controlled rollout change, and manage canary subjects. This page never sends a test email.</p>
                    </div>
                    <div class="launch-readiness rounded-xl border border-{{ $statusColor }}-200 bg-{{ $statusColor }}-50 px-4 py-3 text-right dark:border-{{ $statusColor }}-500/30 dark:bg-{{ $statusColor }}-500/10">
                        <p class="text-xs font-semibold uppercase tracking-wider" style="color:var(--brand-muted)">Launch readiness</p>
                        <p class="mt-1 text-lg font-bold capitalize text-{{ $statusColor }}-700 dark:text-{{ $statusColor }}-300">{{ $status }}</p>
                        <p class="mt-1 text-xs" style="color:var(--brand-muted)">Checked {{ $readiness['evaluated_at'] ?? 'unknown' }}</p>
                    </div>
                </div>
                @if (! empty($readiness['reasons']))
                    <div class="launch-alert mt-5">
                        <p class="text-sm font-semibold">What needs attention</p>
                        <div class="mt-2 flex flex-wrap gap-2">
                            @foreach ($readiness['reasons'] as $reason)
                                <span class="launch-chip">{{ str_replace('_', ' ', $reason) }}</span>
                            @endforeach
                        </div>
                    </div>
                @endif
                @if ($has_overrides)
                    <p class="mt-4 text-xs font-medium" style="color:var(--pumpkin)">Live rollout overrides are active and differ from environment defaults.</p>
                @endif
            </div>

            <div class="launch-grid launch-grid--checks">
                @foreach ($checkCards as $label => $passing)
                    <div class="launch-check {{ $passing ? '' : 'launch-check--bad' }}">
                        <div class="flex items-center justify-between">
                            <span class="text-sm font-medium">{{ $label }}</span>
                            <span class="flex h-6 w-6 items-center justify-center rounded-full {{ $passing ? 'bg-success-100 text-success-700' : 'bg-danger-100 text-danger-700' }}">{{ $passing ? '✓' : '!' }}</span>
                        </div>
                        <p class="mt-2 text-xs {{ $passing ? '' : 'text-danger-600' }}" style="{{ $passing ? 'color:var(--ceramic-green)' : '' }}">{{ $passing ? 'Ready' : 'Needs attention' }}</p>
                    </div>
                @endforeach
            </div>
        </section>

        <section aria-labelledby="rollout-controls-heading" class="launch-section">
            <div>
                <p class="launch-kicker">Section 2</p>
                <h2 id="rollout-controls-heading" class="launch-section-title">Rollout controls</h2>
                <p class="launch-section-copy">Choose who can send. Emergency stop always has priority.</p>
            </div>
            <div class="launch-grid launch-grid--controls">
                <form wire:submit="updateRollout" class="launch-card">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <h3 class="font-semibold" style="color:var(--farm-green)">Rollout strategy</h3>
                            <p class="mt-1 text-sm" style="color:var(--brand-muted)">Changes take effect only after validation succeeds.</p>
                        </div>
                        <span class="launch-chip launch-chip--info">Live control</span>
                    </div>
                    <div class="mt-5 grid gap-4 sm:grid-cols-2">
                        <label class="text-sm font-medium">Rollout mode
                            <select wire:model="rollout_mode" class="fi-select mt-2 block w-full rounded-lg">
                                <option value="disabled">Disabled</option>
                                <option value="canary">Canary</option>
                                <option value="percentage">Percentage</option>
                                <option value="enabled">Enabled</option>
                            </select>
                        </label>
                        <label class="text-sm font-medium">Audience percentage
                            <input type="number" min="0" max="100" wire:model="rollout_percent" class="fi-input mt-2 block w-full rounded-lg" />
                        </label>
                    </div>
                    <button type="submit" wire:confirm="Change the live outbound rollout mode/percent?" class="launch-button">Update rollout</button>
                </form>

                <section class="launch-card">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <h3 class="font-semibold" style="color:var(--farm-green)">Emergency stop</h3>
                            <p class="mt-1 text-sm" style="color:var(--brand-muted)">Stops new outbound eligibility. Queued messages are never deleted or marked failed.</p>
                        </div>
                        <span class="ops-chip {{ $emergency_stop ? 'ops-chip--critical' : 'ops-chip--ready' }}">{{ $emergency_stop ? 'Engaged' : 'Released' }}</span>
                    </div>
                    <label class="mt-5 flex items-center gap-3 rounded-lg border p-3 text-sm" style="border-color:var(--brand-line)">
                        <input type="checkbox" wire:model="emergency_stop" class="rounded border-gray-300 text-danger-600 focus:ring-danger-600" />
                        Engage emergency stop
                    </label>
                    <div class="mt-4 flex flex-wrap items-center gap-3">
                        <button type="button" wire:click="toggleEmergencyStop" wire:confirm="Change the live outbound emergency stop state?" class="launch-button launch-button--danger">Apply emergency state</button>
                        <button type="button" wire:click="clearOverrides" wire:confirm="Clear all live rollout overrides and revert to env defaults?" class="launch-link">Clear overrides</button>
                    </div>
                </section>
            </div>
        </section>

        <section aria-labelledby="canary-guidance-heading" class="launch-section">
            <div>
                <p class="launch-kicker">Section 3</p>
                <h2 id="canary-guidance-heading" class="launch-section-title">Canary audience and guidance</h2>
                <p class="launch-section-copy">Start with a small trusted audience, then use the recommendation before expanding rollout.</p>
            </div>
            <div class="launch-grid launch-grid--guidance">
                <section class="launch-card">
                    <h3 class="font-semibold" style="color:var(--farm-green)">Canary audience</h3>
                    <p class="mt-1 text-sm" style="color:var(--brand-muted)">Allow selected subjects to use outbound during a canary rollout.</p>
                    <form wire:submit="addCanary" class="mt-5 grid gap-3 md:grid-cols-3">
                        <select wire:model="canary_subject_type" class="fi-select rounded-lg">
                            <option value="user">User</option>
                            <option value="inbox">Inbox</option>
                            <option value="domain">Domain</option>
                            <option value="api_key">API key</option>
                        </select>
                        <input type="text" wire:model="canary_subject_id" placeholder="Subject UUID" class="fi-input rounded-lg" />
                        <input type="text" wire:model="canary_label" placeholder="Optional label" class="fi-input rounded-lg" />
                        <button type="submit" class="launch-button launch-button--warm md:col-span-3">Add canary</button>
                    </form>
                    <div class="mt-5 overflow-x-auto">
                        <table class="launch-table">
                            <thead>
                                <tr>
                                    <th>Type</th>
                                    <th>Subject</th>
                                    <th>Label</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($canaries as $row)
                                    <tr>
                                        <td class="capitalize">{{ str_replace('_', ' ', $row['subject_type']) }}</td>
                                        <td class="font-mono text-xs">{{ $row['subject_id'] }}</td>
                                        <td style="color:var(--brand-muted)">{{ $row['label'] ?? '—' }}</td>
                                        <td>
                                            <button type="button" wire:click="removeCanary('{{ $row['id'] }}')" wire:confirm="Remove this canary?" class="text-sm font-medium" style="color:var(--chilli)">Remove</button>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="4" class="py-8 text-center text-sm" style="color:var(--brand-muted)">No active canaries.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </section>

                <section class="launch-card" style="border-color:rgba(128,109,198,.35);background:linear-gradient(135deg,#f3effc,#fff 70%)">
                    <div class="flex items-center justify-between">
                        <div>
                            <h3 class="font-semibold" style="color:var(--true-v)">Pause recommendation</h3>
                            <p class="mt-1 text-sm" style="color:var(--brand-muted)">Advisory only; nothing is disabled automatically.</p>
                        </div>
                        <span class="launch-chip launch-chip--accent">{{ $recommendation['recommendation'] ?? 'unknown' }}</span>
                    </div>
                    @if (! empty($recommendation['reasons']))
                        <div class="mt-4 space-y-2">
                            @foreach ($recommendation['reasons'] as $reason)
                                <div class="rounded-lg px-3 py-2 text-sm" style="background:rgba(128,109,198,.08)">{{ str_replace('_', ' ', $reason) }}</div>
                            @endforeach
                        </div>
                    @else
                        <div class="mt-5 rounded-lg p-4 text-sm" style="background:rgba(58,189,111,.14);color:#146b3a">No pause reasons are currently reported.</div>
                    @endif
                    <details class="mt-5 rounded-lg border p-3 text-sm" style="border-color:rgba(128,109,198,.25)">
                        <summary class="cursor-pointer font-medium">Technical diagnostics</summary>
                        <div class="mt-3 space-y-2 text-xs" style="color:var(--brand-muted)">
                            @foreach (array_keys($checks) as $check)
                                <div class="flex justify-between gap-3">
                                    <span class="capitalize">{{ str_replace('_', ' ', $check) }}</span>
                                    <span>Available</span>
                                </div>
                            @endforeach
                        </div>
                    </details>
                </section>
            </div>
        </section>
    </div>
</x-filament-panels::page>
