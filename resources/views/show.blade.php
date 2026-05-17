@extends('commonplace::layouts.app', [
    'title' => $note->title . ' — Commonplace',
    'description' => 'A note from your commonplace book.',
])

@section('content')
<article class="cp-note">
    <header class="cp-note-header">
        <h1>{{ $note->title }}</h1>

        <div class="cp-note-meta">
            <nav class="cp-breadcrumbs" aria-label="Note path">
                <a href="{{ route('commonplace.index') }}">Notes</a>
                @foreach ($breadcrumbs as $crumb)
                    <span aria-hidden="true">/</span>
                    @if ($loop->last)
                        <span>{{ $crumb['label'] }}</span>
                    @else
                        <a href="{{ $crumb['url'] }}">{{ $crumb['label'] }}</a>
                    @endif
                @endforeach
            </nav>

            <span class="cp-visibility-badge cp-visibility-{{ $note->visibility->value }}">{{ ucfirst($note->visibility->value) }}</span>

            @if ($note->tags->isNotEmpty())
                <ul class="cp-tag-list" role="list" aria-label="Tags">
                    @foreach ($note->tags as $tag)
                        <li class="cp-tag">{{ $tag->name }}</li>
                    @endforeach
                </ul>
            @endif

            @if ($note->updated_at)
                <time datetime="{{ $note->updated_at->toIso8601String() }}">
                    Updated {{ $note->updated_at->format('F j, Y') }}
                </time>
            @endif
        </div>

        <div class="cp-note-actions">
            <a href="{{ route('commonplace.edit', ['path' => $note->path]) }}">Edit</a>
            <a href="{{ route('commonplace.showRaw', ['path' => $note->path]) }}">View markdown</a>
            <a href="{{ route('commonplace.downloadRaw', ['path' => $note->path]) }}">Download markdown</a>

            <form method="POST" action="{{ route('commonplace.destroy', ['path' => $note->path]) }}" class="cp-delete-form" onsubmit="return confirm('Delete this note? This cannot be undone.');">
                @csrf
                @method('DELETE')
                <button type="submit" class="cp-delete-btn">Delete</button>
            </form>
        </div>
    </header>

    <div class="cp-markdown-content">
        {!! $renderedContent !!}
    </div>

    @if ($backlinks->isNotEmpty())
        <aside class="cp-backlinks" aria-labelledby="backlinks-heading">
            <h2 id="backlinks-heading">Linked from</h2>
            <ul>
                @foreach ($backlinks as $backlink)
                    <li><a href="{{ route('commonplace.show', ['path' => $backlink->path]) }}">{{ $backlink->title }}</a></li>
                @endforeach
            </ul>
        </aside>
    @endif
</article>
@endsection
