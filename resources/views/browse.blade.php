@extends('commonplace::layouts.app', [
    'title' => ($folder !== '' ? ucfirst(str_replace('/', ' / ', $folder)) . ' — ' : '') . 'Commonplace',
    'description' => 'Browse vault notes.',
])

@section('content')
<section class="cp-browse" aria-labelledby="cp-browse-heading">
    <div class="cp-section-header">
        <div>
            <nav class="cp-breadcrumbs" aria-label="Vault path">
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
            <h1 id="cp-browse-heading">{{ $folder !== '' ? basename($folder) : 'Notes' }}</h1>
        </div>
        <div class="cp-header-actions">
            <a href="{{ route('commonplace.graph') }}" class="cp-action-secondary">Graph</a>
            <a href="{{ route('commonplace.create') }}" class="cp-action-primary">New Note</a>
        </div>
    </div>

    <form class="cp-search-form" action="{{ route('commonplace.search') }}" method="GET" role="search">
        <input type="search" name="q" placeholder="Search notes..." aria-label="Search notes">
        <button type="submit">Search</button>
        <label class="cp-search-semantic">
            <input type="checkbox" name="semantic" value="1">
            Semantic
        </label>
    </form>

    @if (count($subfolders) > 0 || $notes->isNotEmpty())
        <div class="cp-listing">
            @if (count($subfolders) > 0)
                <ul class="cp-folder-list" role="list" aria-label="Folders">
                    @foreach ($subfolders as $name => $count)
                        <li class="cp-folder-item">
                            <a href="{{ route('commonplace.show', ['path' => $folder !== '' ? $folder . '/' . $name : $name]) }}" class="cp-folder-link">
                                <span class="cp-folder-name">{{ $name }}</span>
                                <span class="cp-folder-count">{{ $count }} {{ \Illuminate\Support\Str::plural('note', $count) }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif

            @if ($notes->isNotEmpty())
                <ul class="cp-file-list" role="list" aria-label="Notes">
                    @foreach ($notes as $note)
                        <li class="cp-file-item">
                            <a href="{{ route('commonplace.show', ['path' => $note->path]) }}" class="cp-file-link">
                                <span class="cp-file-title">{{ $note->title }}</span>
                                @if ($note->tags->isNotEmpty())
                                    <span class="cp-file-tags">
                                        @foreach ($note->tags as $tag)
                                            <span class="cp-tag">{{ $tag->name }}</span>
                                        @endforeach
                                    </span>
                                @endif
                                @if ($note->updated_at)
                                    <time datetime="{{ $note->updated_at->toIso8601String() }}">{{ $note->updated_at->diffForHumans() }}</time>
                                @endif
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    @else
        <div class="cp-empty">
            @if ($folder !== '')
                <p>No notes in this folder. <a href="{{ route('commonplace.create') }}">Create one.</a></p>
            @else
                <p>Your vault is empty. <a href="{{ route('commonplace.create') }}">Create your first note.</a></p>
            @endif
        </div>
    @endif
</section>
@endsection
