@extends('layouts.app')
@section('title','Drafts')
@section('content')
<div class="row" style="justify-content:space-between"><h1>Drafts</h1><a class="btn btn--primary" href="{{ route('outbound-drafts.compose') }}">Compose</a></div>
@if(session('outboundError'))<div class="alert alert--error">{{ session('outboundError') }}</div>@endif
@if($drafts->isEmpty())<div class="card">No drafts yet.</div>@else <div class="card"><table class="table"><thead><tr><th>Operation</th><th>Sender</th><th>Subject</th><th>Recipients</th><th>Attachments</th><th>Edited</th><th></th></tr></thead><tbody>@foreach($drafts as $draft)<tr><td>{{ $draft->operation->label() }}</td><td>{{ $draft->inbox?->full_address }}</td><td>{{ \Illuminate\Support\Str::limit($draft->subject ?: '(No subject)',80) }}</td><td>{{ count($draft->to_recipients ?? []) }}</td><td>{{ count($draft->attachment_ids ?? []) }}</td><td>{{ $draft->updated_at?->diffForHumans() }}</td><td><a class="btn" href="{{ route('outbound-drafts.edit',$draft) }}">Edit</a></td></tr>@endforeach</tbody></table></div>{{ $drafts->links() }}@endif
@endsection
