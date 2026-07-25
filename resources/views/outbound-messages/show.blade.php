@extends('layouts.app')

@section('title', $message->subject ?: 'Outbound message')

@section('content')
    <div class="row" style="justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:1rem;">
        <div>
            <h1 style="margin-bottom:0.25rem;">{{ $message->subject ?: '(no subject)' }}</h1>
            <div class="row muted" style="font-size:0.875rem;">
                <span>ID:</span>
                <span class="copy-id" id="message-id">{{ $message->id }}</span>
                <button type="button" class="btn" data-copy-target="message-id">Copy ID</button>
            </div>
        </div>
        <a href="{{ route('outbound-messages.index') }}" class="btn">Back to list</a>
    </div>

    @if ($banner)
        <div class="alert alert--warning">{{ $banner }}</div>
    @endif

    <div class="stack">
        <div class="card">
            <div class="row" style="justify-content:space-between; flex-wrap:wrap; gap:1.25rem;">
                <div>
                    <div class="muted">State</div>
                    <span class="badge badge--{{ $message->state->value }}">{{ $message->state->label() }}</span>
                </div>
                <div>
                    <div class="muted">Operation</div>
                    {{ $message->operation->label() }}
                </div>
                <div>
                    <div class="muted">Sender inbox</div>
                    {{ $message->inbox->full_address ?? '—' }}
                </div>
                <div>
                    <div class="muted">Created</div>
                    {{ $message->created_at?->toDayDateTimeString() }}
                </div>
                <div>
                    <div class="muted">Sent</div>
                    {{ $message->sent_at?->toDayDateTimeString() ?? '—' }}
                </div>
                <div>
                    <div class="muted">Delivered</div>
                    {{ $message->delivered_at?->toDayDateTimeString() ?? '—' }}
                </div>
                @if ($message->cancelled_at)
                    <div>
                        <div class="muted">Cancelled</div>
                        {{ $message->cancelled_at->toDayDateTimeString() }}
                    </div>
                @endif
            </div>

            @if ($failureCategory)
                <p class="muted" style="margin-bottom:0; margin-top:1rem;">
                    Issue category: {{ str_replace('_', ' ', $failureCategory) }}
                </p>
            @endif

            @if ($attemptSummary['count'] > 0)
                <p class="muted" style="margin-bottom:0;">
                    Delivery attempts: {{ $attemptSummary['count'] }}
                    @if ($attemptSummary['last_category'])
                        (last outcome: {{ str_replace('_', ' ', $attemptSummary['last_category']) }})
                    @endif
                </p>
            @endif

            <div class="row" style="margin-top:1rem;">
                @if ($canCancel)
                    <form method="POST" action="{{ route('outbound-messages.cancel', $message) }}">
                        @csrf
                        <button type="submit" class="btn btn--danger">Cancel message</button>
                    </form>
                @endif

                @if ($canRetry)
                    <form method="POST" action="{{ route('outbound-messages.retry', $message) }}">
                        @csrf
                        <button type="submit" class="btn">Retry</button>
                    </form>
                @endif
            </div>
        </div>

        <div class="card">
            <h2 style="margin-top:0; font-size:1rem;">Recipients</h2>
            <p><strong>To:</strong> {{ implode(', ', $message->to_recipients ?? []) ?: '—' }}</p>
            @if (! empty($message->cc_recipients))
                <p><strong>Cc:</strong> {{ implode(', ', $message->cc_recipients) }}</p>
            @endif
            @if (! empty($message->bcc_recipients))
                <p style="margin-bottom:0;"><strong>Bcc:</strong> {{ implode(', ', $message->bcc_recipients) }}</p>
            @endif
        </div>

        <div class="card">
            <h2 style="margin-top:0; font-size:1rem;">Message body</h2>

            @if ($sanitizedHtmlBody)
                <div class="html-body-frame">{!! $sanitizedHtmlBody !!}</div>
            @elseif ($message->text_body)
                <pre style="white-space:pre-wrap; font-family:inherit; margin:0;">{{ $message->text_body }}</pre>
            @else
                <p class="muted" style="margin:0;">No message body.</p>
            @endif
        </div>

        @if ($attachments->isNotEmpty())
            <div class="card">
                <h2 style="margin-top:0; font-size:1rem;">Attachments</h2>
                <table class="table">
                    <thead>
                        <tr>
                            <th>File</th>
                            <th>Type</th>
                            <th>Size</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($attachments as $attachment)
                            <tr>
                                <td>{{ $attachment->original_filename }}</td>
                                <td>{{ $attachment->mime_type }}</td>
                                <td>{{ number_format($attachment->size_bytes / 1024, 1) }} KB</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        <div class="card">
            <h2 style="margin-top:0; font-size:1rem;">Timeline</h2>

            @if (empty($timeline))
                <p class="muted" style="margin:0;">No timeline events yet.</p>
            @else
                <table class="table">
                    <thead>
                        <tr>
                            <th>Event</th>
                            <th>When</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($timeline as $entry)
                            <tr>
                                <td>
                                    {{ $entry['label'] }}
                                    @if (! empty($entry['category']))
                                        <span class="muted">({{ str_replace('_', ' ', $entry['category']) }})</span>
                                    @endif
                                </td>
                                <td>{{ $entry['occurred_at'] ? \Illuminate\Support\Carbon::parse($entry['occurred_at'])->toDayDateTimeString() : '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>

    @push('head')
        <script>
            document.addEventListener('click', function (event) {
                var trigger = event.target.closest('[data-copy-target]');
                if (! trigger) {
                    return;
                }
                var target = document.getElementById(trigger.getAttribute('data-copy-target'));
                if (target && navigator.clipboard) {
                    navigator.clipboard.writeText(target.textContent.trim());
                }
            });
        </script>
    @endpush
@endsection
