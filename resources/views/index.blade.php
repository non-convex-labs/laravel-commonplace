@extends('commonplace::layouts.app', ['title' => 'Commonplace', 'description' => 'Your knowledge vault.'])

@section('content')
<section class="cp-index" aria-labelledby="cp-heading">
    <div class="cp-section-header">
        <h1 id="cp-heading">Notes</h1>
        <a href="{{ route('commonplace.create') }}" class="cp-action-primary">New Note</a>
    </div>

    <form class="cp-search-form" action="{{ route('commonplace.search') }}" method="GET" role="search">
        <input type="search" name="q" placeholder="Search notes..." aria-label="Search notes">
        <button type="submit">Search</button>
        <label class="cp-search-semantic">
            <input type="checkbox" name="semantic" value="1">
            Semantic
        </label>
    </form>

    @if (count($subfolders) > 0)
        <ul class="cp-folder-list" role="list" aria-label="Folders">
            @foreach ($subfolders as $name => $count)
                <li class="cp-folder-item">
                    <a href="{{ route('commonplace.show', ['path' => $name]) }}" class="cp-folder-link">
                        <span class="cp-folder-name">{{ $name }}</span>
                        <span class="cp-folder-count">{{ $count }} {{ \Illuminate\Support\Str::plural('note', $count) }}</span>
                    </a>
                </li>
            @endforeach
        </ul>
    @endif

    <div class="cp-note-list">
        @forelse ($notes as $note)
            <article class="cp-note-card">
                <div class="cp-note-card-content">
                    <h2><a href="{{ route('commonplace.show', ['path' => $note->path]) }}" class="cp-note-card-link">{{ $note->title }}</a></h2>

                    <nav class="cp-note-path" aria-label="Note path">
                        @foreach (explode('/', $note->path) as $segment)
                            @if (! $loop->first)
                                <span aria-hidden="true">/</span>
                            @endif
                            <span>{{ $segment }}</span>
                        @endforeach
                    </nav>

                    @if ($note->tags->isNotEmpty())
                        <ul class="cp-tag-list" role="list" aria-label="Tags">
                            @foreach ($note->tags as $tag)
                                <li class="cp-tag">{{ $tag->name }}</li>
                            @endforeach
                        </ul>
                    @endif

                    @if ($note->updated_at)
                        <time datetime="{{ $note->updated_at->toIso8601String() }}">
                            Updated {{ $note->updated_at->diffForHumans() }}
                        </time>
                    @endif
                </div>
            </article>
        @empty
            @if (count($subfolders) === 0)
                <div class="cp-empty">
                    <p>Your vault is empty. <a href="{{ route('commonplace.create') }}">Create your first note.</a></p>
                </div>
            @endif
        @endforelse
    </div>
</section>
@endsection
