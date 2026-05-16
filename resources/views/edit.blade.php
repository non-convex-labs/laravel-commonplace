@extends('commonplace::layouts.app', [
    'title' => 'Edit ' . $note->title . ' — Commonplace',
    'description' => 'Edit a note.',
])

@section('content')
<section class="cp-form-page" aria-labelledby="cp-edit-heading">
    <h1 id="cp-edit-heading">Edit Note</h1>

    @if ($errors->any())
        <div class="cp-alert cp-alert-error" role="alert">
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('commonplace.update', ['path' => $note->path]) }}" class="cp-form">
        @csrf
        @method('PUT')

        <div class="cp-field">
            <label for="new_path">Path</label>
            <input
                type="text"
                id="new_path"
                name="new_path"
                value="{{ old('new_path', $note->path) }}"
                required
            >
        </div>

        <div class="cp-field">
            <label for="content">Content (Markdown)</label>
            <textarea id="content" name="content" rows="20" required>{{ old('content', $note->content) }}</textarea>
        </div>

        <div class="cp-field">
            <label for="tags">Tags <span class="cp-field-hint">(comma-separated)</span></label>
            <input
                type="text"
                id="tags"
                name="tags"
                value="{{ old('tags', $note->tags->pluck('name')->implode(', ')) }}"
                placeholder="ai, project, notes"
            >
        </div>

        <div class="cp-field">
            <label for="visibility">Visibility</label>
            <select id="visibility" name="visibility">
                <option value="private" @selected(old('visibility', $note->visibility) === 'private')>Private</option>
                <option value="shared" @selected(old('visibility', $note->visibility) === 'shared')>Shared</option>
                <option value="public" @selected(old('visibility', $note->visibility) === 'public')>Public</option>
            </select>
        </div>

        <div class="cp-form-actions">
            <button type="submit" class="cp-action-primary">Update Note</button>
            <a href="{{ route('commonplace.show', ['path' => $note->path]) }}" class="cp-action-secondary">Cancel</a>
        </div>
    </form>

    <form method="POST" action="{{ route('commonplace.destroy', ['path' => $note->path]) }}" class="cp-delete-section" onsubmit="return confirm('Delete this note? This cannot be undone.');">
        @csrf
        @method('DELETE')
        <button type="submit" class="cp-delete-btn">Delete this note</button>
    </form>
</section>
@endsection
