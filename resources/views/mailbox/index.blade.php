@extends('layouts.app')

@section('title', 'Inbox')

@section('content')
    @if (! empty($commercialSummary['features']['inbox.max_active']))
        @php($inboxQuota = $commercialSummary['features']['inbox.max_active'])
        <div class="commercial-usage-banner" role="status">
            <strong>Plan: {{ $commercialSummary['plan'] ?? 'free' }}</strong>
            <span>Inboxes {{ $inboxQuota['used'] ?? 0 }}/{{ $inboxQuota['limit'] ?? '∞' }}</span>
            @if (($commercialSummary['upgrade_required'] ?? false) === true)
                <span>Upgrade to Premium to continue.</span>
            @endif
        </div>
    @endif
    <div class="mail-workspace">
        <aside class="mailbox-list" aria-label="Your inboxes">
            <div class="pane-heading">Your inboxes</div>
            @forelse ($inboxes as $inbox)
                <a class="inbox-card {{ $selected?->id === $inbox->id ? 'is-selected' : '' }}" href="{{ route('mailbox.index', ['inbox' => $inbox->id]) }}">
                    <strong>{{ $inbox->display_name ?: $inbox->full_address }}</strong>
                    <span>{{ $inbox->full_address }}</span>
                    <small>{{ $inbox->message_count }} messages</small>
                </a>
            @empty
                <p class="empty-copy">You have no active inboxes yet.</p>
            @endforelse
        </aside>

        <section class="message-list" aria-label="Messages">
            <div class="pane-toolbar">
                <div><strong>{{ $selected?->full_address ?: 'Select an inbox' }}</strong></div>
                <a class="icon-button" href="{{ route('mailbox.index', ['inbox' => $selected?->id]) }}" aria-label="Refresh inbox">↻</a>
            </div>
            @if (! $selected)
                <div class="empty-state"><div class="empty-state__icon"></div><h2>No inbox selected</h2><p>Create or activate an inbox to start receiving messages.</p></div>
            @elseif ($emails?->isNotEmpty())
                @foreach ($emails as $email)
                    <a class="mail-row {{ $email->is_read ? '' : 'is-unread' }} {{ $selectedEmail?->id === $email->id ? 'is-selected' : '' }}" href="{{ route('mailbox.index', ['inbox' => $selected->id, 'email' => $email->id]) }}">
                        <div class="mail-row__sender">{{ $email->sender_name ?: $email->sender_email }}</div>
                        <div class="mail-row__subject">{{ $email->subject ?: '(No subject)' }}</div>
                        <time>{{ $email->received_at?->diffForHumans() }}</time>
                    </a>
                @endforeach
                <div class="pagination">{{ $emails->withQueryString()->links() }}</div>
            @else
                <div class="empty-state"><div class="empty-state__icon"></div><h2>No messages found</h2><p>New messages for this inbox will appear here.</p></div>
            @endif
        </section>

        <section class="message-preview" aria-label="Message preview">
            @if ($selectedEmail)
                <article class="preview-card">
                    <div class="preview-card__meta"><span>From</span><strong>{{ $selectedEmail->sender_name ?: $selectedEmail->sender_email }}</strong><span>{{ $selectedEmail->received_at?->toDayDateTimeString() }}</span></div>
                    <h1>{{ $selectedEmail->subject ?: '(No subject)' }}</h1>
                    <div class="preview-card__body">{{ $selectedEmail->body?->text_body ?: 'A safe text preview is unavailable for this message.' }}</div>
                </article>
            @else
                <div class="empty-state"><div class="empty-state__icon"></div><h2>Select a message</h2><p>Choose a message from the list to view its safe preview.</p></div>
            @endif
        </section>
    </div>
@endsection
