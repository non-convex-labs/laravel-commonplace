@extends('commonplace::layouts.app', [
    'title' => 'Revision ' . substr($version->content_hash, 0, 8) . ' — ' . $path . ' — Commonplace',
    'description' => 'A historical revision of a note.',
])

@section('content')
<article class="cp-note cp-history-revision">
    <header class="cp-note-header">
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
            <a href="{{ route('commonplace.history', ['path' => $path]) }}">History</a>
            <span aria-hidden="true">/</span>
            <span>{{ substr($version->content_hash, 0, 8) }}</span>
        </nav>

        <h1>{{ $note?->title ?? $path }}</h1>

        <div class="cp-note-meta">
            <span class="cp-history-revision-badge">Revision (read-only)</span>
            <time datetime="{{ $version->created_at->toIso8601String() }}">
                Captured {{ $version->created_at->format('F j, Y \a\t H:i') }}
            </time>
            <span class="cp-history-author">
                by {{ $version->author?->name ?? '(deleted user)' }}
            </span>
            <code class="cp-history-hash">{{ substr($version->content_hash, 0, 8) }}</code>
        </div>
    </header>

    <div class="cp-markdown-content">
        {!! $renderedContent !!}
    </div>
</article>
@endsection
