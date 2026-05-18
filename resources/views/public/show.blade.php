<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $note->title }} — Commonplace</title>
    <link rel="stylesheet" href="{{ route('commonplace.asset.css') }}">
</head>
<body class="commonplace">
    <main class="commonplace-main" id="main" tabindex="-1">
        <article class="cp-note">
            <header class="cp-note-header">
                <h1>{{ $note->title }}</h1>

                <div class="cp-note-meta">
                    @if ($note->updated_at)
                        <time datetime="{{ $note->updated_at->toIso8601String() }}">
                            Updated {{ $note->updated_at->format('F j, Y') }}
                        </time>
                    @endif
                </div>

                <div class="cp-note-actions">
                    <a href="{{ route('commonplace.public.showRaw', ['path' => $note->path]) }}">View markdown</a>
                </div>
            </header>

            <div class="cp-markdown-content">
                {!! $renderedContent !!}
            </div>
        </article>
    </main>
</body>
</html>
