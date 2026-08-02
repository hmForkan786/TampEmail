@extends('layouts.app')

@section('title', 'Drafts')
@section('mailTitle', 'Drafts')
@section('mailNav', 'drafts')

@section('content')
    <div class="mail-page">
        <div class="row" style="justify-content:space-between; margin-bottom:1rem;">
            <p class="muted" style="margin:0;">Saved drafts ready to send or schedule.</p>
            <a class="btn btn--primary" href="{{ route('outbound-drafts.compose') }}">Compose</a>
        </div>

        @if ($drafts->isEmpty())
            <div class="mail-empty" style="min-height: 18rem;">
                <div class="mail-empty__art" aria-hidden="true">
                    <div class="mail-empty__box mail-empty__box--back"></div>
                    <div class="mail-empty__box mail-empty__box--front"></div>
                </div>
                <h2>No messages found</h2>
            </div>
        @else
            <div class="card">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Operation</th>
                            <th>Sender</th>
                            <th>Subject</th>
                            <th>Recipients</th>
                            <th>Attachments</th>
                            <th>Edited</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($drafts as $draft)
                            <tr>
                                <td>{{ $draft->operation->label() }}</td>
                                <td>{{ $draft->inbox?->full_address }}</td>
                                <td>{{ \Illuminate\Support\Str::limit($draft->subject ?: '(No subject)', 80) }}</td>
                                <td>{{ count($draft->to_recipients ?? []) }}</td>
                                <td>{{ count($draft->attachment_ids ?? []) }}</td>
                                <td>{{ $draft->updated_at?->diffForHumans() }}</td>
                                <td><a class="btn" href="{{ route('outbound-drafts.edit', $draft) }}">Edit</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            {{ $drafts->links() }}
        @endif
    </div>
@endsection
