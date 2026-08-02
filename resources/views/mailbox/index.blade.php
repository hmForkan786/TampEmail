@extends('layouts.app')

@section('title', request('folder') === 'starred' ? 'Starred' : 'Inbox')
@section('mailTitle', request('folder') === 'starred' ? 'Starred' : 'Inbox')
@section('mailNav', 'inbox')

@section('mailRail')
    <div class="mail-rail__tools">
        <a href="{{ route('settings.index') }}" class="mail-icon-btn" aria-label="Add inbox" title="Add inbox">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14"></path><path d="M12 5v14"></path></svg>
        </a>
        <a href="{{ route('mailbox.index', array_filter(['inbox' => $selected?->id, 'folder' => request('folder')])) }}" class="mail-icon-btn" aria-label="Refresh" title="Refresh">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"></path><path d="M21 3v5h-5"></path><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"></path><path d="M8 16H3v5"></path></svg>
        </a>
    </div>

    @forelse ($inboxes as $inbox)
        <a
            class="mail-account {{ $selected?->id === $inbox->id ? 'is-selected' : '' }}"
            href="{{ route('mailbox.index', array_filter(['inbox' => $inbox->id, 'folder' => request('folder')])) }}"
        >
            <span class="mail-account__address">{{ $inbox->full_address }}</span>
            <div class="mail-account__actions">
                <div class="mail-account__actions-left">
                    <span class="mail-icon-btn is-mail" aria-hidden="true" title="{{ $inbox->message_count }} messages">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"></rect><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"></path></svg>
                    </span>
                </div>
                <div class="mail-account__actions-right">
                    <span class="mail-icon-btn is-trash" aria-hidden="true" title="Manage">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"></path><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"></path><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"></path></svg>
                    </span>
                    <span class="mail-icon-btn" aria-hidden="true" title="Settings">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                    </span>
                </div>
            </div>
        </a>
    @empty
        <p class="mail-rail__end">No inboxes yet</p>
    @endforelse

    @if ($inboxes->isNotEmpty())
        <div class="mail-rail__end">No more data</div>
    @endif
@endsection

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

    <div class="mail-toolbar">
        <div class="mail-toolbar__left">
            <input type="checkbox" class="mail-check" aria-label="Select all" disabled>
            <button type="button" class="mail-icon-btn" aria-label="Sort" title="Sort" disabled>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m21 16-4 4-4-4"></path><path d="M17 20V4"></path><path d="m3 8 4-4 4 4"></path><path d="M7 4v16"></path><circle cx="12" cy="12" r="1"></circle></svg>
            </button>
            <a
                href="{{ route('mailbox.index', array_filter(['inbox' => $selected?->id, 'folder' => request('folder'), 'email' => request('email')])) }}"
                class="mail-icon-btn"
                aria-label="Refresh messages"
                title="Refresh"
            >
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"></path><path d="M21 3v5h-5"></path><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"></path><path d="M8 16H3v5"></path></svg>
            </a>
        </div>
        <div class="mail-toolbar__right">
            <button type="button" class="mail-icon-btn" aria-label="View options" title="View" disabled>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="7" height="7" x="3" y="3" rx="1"></rect><rect width="7" height="7" x="14" y="3" rx="1"></rect><rect width="7" height="7" x="14" y="14" rx="1"></rect><rect width="7" height="7" x="3" y="14" rx="1"></rect></svg>
            </button>
        </div>
    </div>

    @if (request('folder') === 'starred')
        <div class="mail-empty">
            <div class="mail-empty__art" aria-hidden="true">
                <div class="mail-empty__box mail-empty__box--back"></div>
                <div class="mail-empty__box mail-empty__box--front"></div>
            </div>
            <h2>No messages found</h2>
        </div>
    @elseif (! $selected)
        <div class="mail-empty">
            <div class="mail-empty__art" aria-hidden="true">
                <div class="mail-empty__box mail-empty__box--back"></div>
                <div class="mail-empty__box mail-empty__box--front"></div>
            </div>
            <h2>No messages found</h2>
            <p>Create or activate an inbox to start receiving messages.</p>
        </div>
    @elseif ($emails?->isNotEmpty())
        <div class="mail-rows">
            @foreach ($emails as $email)
                <a
                    class="mail-row {{ $email->is_read ? '' : 'is-unread' }} {{ $selectedEmail?->id === $email->id ? 'is-selected' : '' }}"
                    href="{{ route('mailbox.index', ['inbox' => $selected->id, 'email' => $email->id]) }}"
                >
                    <div class="mail-row__sender">{{ $email->sender_name ?: $email->sender_email }}</div>
                    <div class="mail-row__subject">{{ $email->subject ?: '(No subject)' }}</div>
                    <time>{{ $email->received_at?->diffForHumans() }}</time>
                </a>
            @endforeach
        </div>
        <div class="pagination">{{ $emails->withQueryString()->links() }}</div>

        @if ($selectedEmail)
            <section class="mail-preview" aria-label="Message preview">
                <div class="mail-preview__meta">
                    <span>From <strong style="color:#fff">{{ $selectedEmail->sender_name ?: $selectedEmail->sender_email }}</strong></span>
                    <span>{{ $selectedEmail->received_at?->toDayDateTimeString() }}</span>
                </div>
                <h1>{{ $selectedEmail->subject ?: '(No subject)' }}</h1>
                <div class="mail-preview__body">{{ $selectedEmail->body?->text_body ?: 'A safe text preview is unavailable for this message.' }}</div>
            </section>
        @endif
    @else
        <div class="mail-empty">
            <div class="mail-empty__art" aria-hidden="true">
                <div class="mail-empty__box mail-empty__box--back"></div>
                <div class="mail-empty__box mail-empty__box--front"></div>
            </div>
            <h2>No messages found</h2>
        </div>
    @endif
@endsection
