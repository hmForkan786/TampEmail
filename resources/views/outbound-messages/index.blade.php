@extends('layouts.app')

@section('title', ($filters['state'] ?? '') === 'scheduled' ? 'Scheduled' : 'Outbound messages')
@section('mailTitle', ($filters['state'] ?? '') === 'scheduled' ? 'Scheduled' : 'Outbound Messages')
@section('mailNav', ($filters['state'] ?? '') === 'scheduled' ? 'scheduled' : 'outbound')

@section('content')
    <div class="mail-page">
    <div class="row" style="justify-content:space-between; margin-bottom:1.25rem;">
        <div>
            <p class="muted" style="margin:0;">Sent, queued, delivered, failed, and cancelled messages you've sent.</p>
        </div>
    </div>

    @if ($banner)
        <div class="alert alert--warning">{{ $banner }}</div>
    @endif

    <form method="GET" class="filters">
        <div class="form-field">
            <label for="state">Status</label>
            <select id="state" name="state">
                <option value="">All</option>
                @foreach ($states as $value => $label)
                    <option value="{{ $value }}" @selected(($filters['state'] ?? '') === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>

        <div class="form-field">
            <label for="operation">Operation</label>
            <select id="operation" name="operation">
                <option value="">All</option>
                @foreach ($operations as $value => $label)
                    <option value="{{ $value }}" @selected(($filters['operation'] ?? '') === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>

        <div class="form-field">
            <label for="inbox_id">Sender inbox ID</label>
            <input id="inbox_id" type="text" name="inbox_id" value="{{ $filters['inbox_id'] ?? '' }}">
        </div>

        <div class="form-field">
            <label for="recipient">Recipient</label>
            <input id="recipient" type="text" name="recipient" value="{{ $filters['recipient'] ?? '' }}">
        </div>

        <div class="form-field">
            <label for="date_from">From</label>
            <input id="date_from" type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}">
        </div>

        <div class="form-field">
            <label for="date_to">To</label>
            <input id="date_to" type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}">
        </div>

        <div class="form-field">
            <label for="has_attachments">Attachments</label>
            <select id="has_attachments" name="has_attachments">
                <option value="">Either</option>
                <option value="1" @selected(($filters['has_attachments'] ?? '') === '1')>With attachments</option>
                <option value="0" @selected(($filters['has_attachments'] ?? '') === '0')>Without attachments</option>
            </select>
        </div>

        <div class="form-field">
            <button type="submit" class="btn btn--primary">Filter</button>
            <a href="{{ route('outbound-messages.index') }}" class="btn">Clear</a>
        </div>
    </form>

    <div class="card">
        @if ($messages->isEmpty())
            <p class="muted" style="margin:0;">No outbound messages found.</p>
        @else
            <table class="table">
                <thead>
                    <tr>
                        <th>Subject</th>
                        <th>Recipient</th>
                        <th>Operation</th>
                        <th>Sender inbox</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th>Attachments</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($messages as $row)
                        <tr>
                            <td><a href="{{ route('outbound-messages.show', $row['id']) }}">{{ $row['subject'] ?: '(no subject)' }}</a></td>
                            <td>
                                {{ $row['primary_recipient'] ?? '—' }}
                                @if ($row['additional_recipients'] > 0)
                                    <span class="muted">+{{ $row['additional_recipients'] }}</span>
                                @endif
                            </td>
                            <td>{{ $row['operation_label'] }}</td>
                            <td>{{ $row['inbox_address'] ?? '—' }}</td>
                            <td>
                                <span class="badge badge--{{ $row['state'] }}">{{ $row['state_label'] }}</span>
                                @if ($row['failure_category'])
                                    <div class="muted" style="font-size:0.75rem;">{{ str_replace('_', ' ', $row['failure_category']) }}</div>
                                @endif
                            </td>
                            <td>{{ $row['created_at']?->toDayDateTimeString() }}
                                @if ($row['state'] === 'scheduled' && $row['scheduled_at'])
                                    <div class="muted" style="font-size:0.75rem;">Due {{ $row['scheduled_at']->toDayDateTimeString() }}</div>
                                @endif
                            </td>
                            <td>{{ $row['attachment_count'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <div class="pagination">{{ $messages->links() }}</div>
        @endif
    </div>
    </div>
@endsection
