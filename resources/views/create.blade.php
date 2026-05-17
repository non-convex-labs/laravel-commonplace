@extends('commonplace::layouts.app', [
    'title' => 'New Note — Commonplace',
    'description' => 'Create a new note.',
])

@section('content')
<section class="cp-form-page" aria-labelledby="cp-create-heading">
    <h1 id="cp-create-heading">New Note</h1>

    @if ($errors->any())
        <div class="cp-alert cp-alert-error" role="alert">
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('commonplace.store') }}" class="cp-form">
        @csrf

        <div class="cp-field">
            <label for="path">Path <span class="cp-field-hint">(e.g. projects/alpha/roadmap)</span></label>
            <input
                type="text"
                id="path"
                name="path"
                value="{{ old('path') }}"
                placeholder="projects/alpha/roadmap"
                required
                aria-describedby="path-hint"
            >
            <p id="path-hint" class="cp-field-hint">Use forward slashes to organise notes into folders.</p>
        </div>

        <div class="cp-field">
            <label for="content">Content (Markdown)</label>
            <textarea id="content" name="content" rows="20" required>{{ old('content') }}</textarea>
        </div>

        <div class="cp-field">
            <label for="tags">Tags <span class="cp-field-hint">(comma-separated)</span></label>
            <input
                type="text"
                id="tags"
                name="tags"
                value="{{ old('tags') }}"
                placeholder="ai, project, notes"
            >
        </div>

        <div class="cp-field">
            <label for="visibility">Visibility</label>
            <select id="visibility" name="visibility">
                <option value="private" @selected(old('visibility', 'private') === 'private')>Private</option>
                <option value="public" @selected(old('visibility') === 'public')>Public</option>
            </select>
        </div>

        <div class="cp-form-actions">
            <button type="submit" class="cp-action-primary">Create Note</button>
            <a href="{{ route('commonplace.index') }}" class="cp-action-secondary">Cancel</a>
        </div>
    </form>
</section>
@endsection
