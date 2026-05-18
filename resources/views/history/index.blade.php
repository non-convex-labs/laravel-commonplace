@extends('commonplace::layouts.app', [
    'title' => 'History — ' . $path . ' — Commonplace',
    'description' => 'Version history for a note.',
])

@section('content')
<section class="cp-history" aria-labelledby="cp-history-heading">
    <header class="cp-section-header">
        <div>
            <nav class="cp-breadcrumbs" aria-label="Note path">
                <a href="{{ route('commonplace.index') }}">Notes</a>
                @foreach ($breadcrumbs as $crumb)
                    <span aria-hidden="true">/</span>
                    @if ($loop->last)
                        @if ($note)
                            <a href="{{ $crumb['url'] }}">{{ $crumb['label'] }}</a>
                        @else
                            <span>{{ $crumb['label'] }}</span>
                        @endif
                    @else
                        <a href="{{ $crumb['url'] }}">{{ $crumb['label'] }}</a>
                    @endif
                @endforeach
                <span aria-hidden="true">/</span>
                <span>History</span>
            </nav>
            <h1 id="cp-history-heading">Version history</h1>
            <p class="cp-history-path">{{ $path }}</p>
        </div>
        @if ($note)
            <div class="cp-header-actions">
                <a href="{{ route('commonplace.show', ['path' => $note->path]) }}" class="cp-action-secondary">View live note</a>
            </div>
        @endif
    </header>

    @if ($note === null)
        <div class="cp-alert cp-alert-info" role="status">
            (note deleted) — showing captured snapshots.
        </div>
    @endif

    @if ($versions->isEmpty())
        <div class="cp-empty">
            <p>No prior revisions recorded for this note yet.</p>
        </div>
    @else
        <ol class="cp-history-list" role="list" aria-label="Versions, newest first">
            @foreach ($versions as $version)
                <li class="cp-history-item">
                    <a href="{{ route('commonplace.historyVersion', ['path' => $path, 'version' => $version->id]) }}" class="cp-history-link">
                        <time datetime="{{ $version->created_at->toIso8601String() }}" class="cp-history-time">
                            {{ $version->created_at->diffForHumans() }}
                            <span class="cp-history-absolute">({{ $version->created_at->format('Y-m-d H:i') }})</span>
                        </time>
                        <span class="cp-history-author">
                            {{ $version->author?->name ?? '(deleted user)' }}
                        </span>
                        <code class="cp-history-hash">{{ substr($version->content_hash, 0, 8) }}</code>
                    </a>
                </li>
            @endforeach
        </ol>
    @endif
</section>
@endsection
