@extends('settings.layout')

@section('title', 'Billing settings')

@section('settings')
    <div class="settings-card">
        <h1 style="margin-top:0;">Billing</h1>
        <p class="settings-help">Read/control surface only. Direct subscription status or entitlement edits are not available here.</p>
        <p>Plan: {{ $billing['plan_name'] ?? $billing['plan'] ?? 'n/a' }}</p>
        @if ($billing['subscription'])
            <p>Status: {{ $billing['subscription']['status'] }}</p>
            <p>Ends: {{ $billing['subscription']['ends_at'] ?? 'n/a' }}</p>
            <p>Cancel at period end: {{ $billing['subscription']['cancel_at_period_end'] ? 'yes' : 'no' }}</p>
        @endif
    </div>

    <div class="settings-card">
        <h2>Usage</h2>
        @foreach (($billing['usage']['features'] ?? []) as $feature => $snapshot)
            <p>
                <strong>{{ $feature }}</strong>:
                used {{ $snapshot['used'] ?? 0 }}
                @if ($snapshot['unlimited'] ?? false)
                    / unlimited
                @else
                    / {{ $snapshot['limit'] ?? 0 }} (remaining {{ $snapshot['remaining'] ?? 0 }})
                @endif
            </p>
        @endforeach
    </div>

    <div class="settings-card">
        <h2>Billing preferences</h2>
        <form method="POST" action="{{ route('settings.billing.preferences') }}">
            @csrf
            @method('PUT')
            <div class="form-field">
                <label for="billing_email">Billing email</label>
                <input id="billing_email" name="billing_email" type="email" value="{{ old('billing_email', $billing['preferences']['billing_email']) }}">
            </div>
            <div class="form-field">
                <label for="invoice_name">Invoice name</label>
                <input id="invoice_name" name="invoice_name" type="text" value="{{ old('invoice_name', $billing['preferences']['invoice_name']) }}">
            </div>
            <div class="form-field">
                <label for="invoice_address">Invoice address</label>
                <textarea id="invoice_address" name="invoice_address" rows="3">{{ old('invoice_address', $billing['preferences']['invoice_address']) }}</textarea>
            </div>
            <div class="form-field">
                <label for="invoice_locale">Invoice locale</label>
                <input id="invoice_locale" name="invoice_locale" type="text" value="{{ old('invoice_locale', $billing['preferences']['invoice_locale']) }}">
            </div>
            <div class="form-field">
                <label for="tax_identifier">Tax identifier (foundation)</label>
                <input id="tax_identifier" name="tax_identifier" type="text" placeholder="{{ $billing['preferences']['tax_identifier_masked'] }}">
            </div>
            <button class="btn btn--primary" type="submit">Save billing preferences</button>
        </form>
    </div>

    <div class="settings-card">
        <h2>Checkout / renew</h2>
        <p class="settings-help">Gateways: {{ implode(', ', $billing['gateways'] ?: ['none configured']) }}</p>
        <form method="POST" action="{{ route('settings.billing.checkout') }}">
            @csrf
            <div class="form-field">
                <label for="plan_id">Plan ID</label>
                <input id="plan_id" name="plan_id" type="text" required>
            </div>
            <div class="form-field">
                <label for="gateway">Gateway</label>
                <select id="gateway" name="gateway" required>
                    @foreach ($billing['gateways'] as $gateway)
                        <option value="{{ $gateway }}">{{ $gateway }}</option>
                    @endforeach
                </select>
            </div>
            <div class="form-field">
                <label for="billing_cycle">Billing cycle</label>
                <select id="billing_cycle" name="billing_cycle">
                    <option value="monthly">monthly</option>
                    <option value="yearly">yearly</option>
                </select>
            </div>
            <input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
            <button class="btn btn--primary" type="submit">Start checkout</button>
        </form>

        @if (($billing['subscription']['cancel_at_period_end'] ?? false) === false && $billing['subscription'])
            <form method="POST" action="{{ route('settings.billing.cancel') }}" style="margin-top:1rem;">
                @csrf
                <button class="btn btn--danger" type="submit">Cancel at period end</button>
            </form>
        @endif
    </div>

    <div class="settings-card">
        <h2>Invoices</h2>
        <div class="settings-list">
            @forelse ($billing['invoices'] as $invoice)
                <div class="settings-list-item">
                    <p>{{ $invoice->invoice_number }} — {{ $invoice->status->value }}</p>
                    <a class="btn" href="{{ route('settings.billing.invoices.download', $invoice->getKey()) }}">Download PDF</a>
                </div>
            @empty
                <p class="settings-help">No invoices yet.</p>
            @endforelse
        </div>
    </div>
@endsection
