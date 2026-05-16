@extends('commonplace::layouts.app', [
    'title' => 'Search' . ($query ? ' — ' . $query : '') . ' — Commonplace',
    'description' => 'Search results for notes.',
])

@section('content')
<section class="cp-search" aria-labelledby="cp-search-heading">
    <h1 id="cp-search-heading">
        @if ($query)
            Search results for &ldquo;{{ $query }}&rdquo;
        @else
            Search
        @endif
    </h1>

    <form class="cp-search-form" action="{{ route('commonplace.search') }}" method="GET" role="search">
        <input
            type="search"
            name="q"
            value="{{ $query }}"
            placeholder="Search notes..."
            aria-label="Search notes"
            autofocus
        >
        <button type="submit">Search</button>
        <label class="cp-search-semantic">
            <input type="checkbox" name="semantic" value="1" @checked($semantic)>
            Semantic
        </label>
    </form>

    @if ($query)
        <p class="cp-search-count">
            {{ $notes->count() }} {{ \Illuminate\Support\Str::plural('result', $notes->count()) }} found
        </p>

        <div class="cp-search-results">
            @forelse ($notes as $note)
                <article class="cp-search-result">
                    <a href="{{ route('commonplace.show', ['path' => $note->path]) }}" class="cp-search-result-link">
                        <h2>{{ $note->title }}</h2>

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
                    </a>
                </article>
            @empty
                <div class="cp-empty">
                    <p>No notes matched your search. Try different keywords or <a href="{{ route('commonplace.index') }}">browse all notes</a>.</p>
                </div>
            @endforelse
        </div>
    @else
        <div class="cp-empty">
            <p>Enter a search term to find notes.</p>
        </div>
    @endif
</section>
@endsection
