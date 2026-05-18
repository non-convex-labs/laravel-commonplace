<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Http\Controllers;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use NonConvexLabs\Commonplace\Enums\Visibility;
use NonConvexLabs\Commonplace\Models\Note;
use NonConvexLabs\Commonplace\Models\NoteVersion;
use NonConvexLabs\Commonplace\Services\Commonplace;
use NonConvexLabs\Commonplace\Services\JournalCalendar;
use NonConvexLabs\Commonplace\Services\MarkdownRenderer;
use NonConvexLabs\Commonplace\Services\NoteBrowser;
use Symfony\Component\HttpFoundation\StreamedResponse;

class NoteController extends Controller
{
    public function __construct(
        private readonly Commonplace $commonplace,
        private readonly MarkdownRenderer $markdown,
        private readonly NoteBrowser $noteBrowser,
        private readonly JournalCalendar $journalCalendar,
    ) {}

    public function index(Request $request): View
    {
        return $this->browseFolder('', $request);
    }

    public function browse(Request $request, string $path = ''): View
    {
        return $this->browseFolder($path, $request);
    }

    public function show(Request $request, string $path): View
    {
        $user = $request->user();

        try {
            $note = $this->commonplace->readNote($path, $user);
        } catch (ModelNotFoundException) {
            if ($path === 'journal' || str_starts_with($path, 'journal/')) {
                $selectedDate = $request->query('date');
                if (! is_string($selectedDate) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate)) {
                    $selectedDate = null;
                }

                return $this->journal(
                    $request,
                    (int) ($request->query('year') ?: date('Y')),
                    (int) ($request->query('month') ?: date('n')),
                    $selectedDate,
                );
            }

            return $this->browseFolder($path, $request);
        } catch (AuthorizationException) {
            abort(403);
        }

        $backlinks = $this->commonplace->getBacklinks($path, $user);

        return view('commonplace::show', [
            'note' => $note,
            'renderedContent' => $this->markdown->renderNote($note->content),
            'backlinks' => $backlinks,
            'breadcrumbs' => $this->buildBreadcrumbs($note->path),
        ]);
    }

    public function showRaw(Request $request, string $path): Response
    {
        try {
            $note = $this->commonplace->readNote($path, $request->user());
        } catch (ModelNotFoundException) {
            abort(404);
        } catch (AuthorizationException) {
            abort(403);
        }

        return response($this->buildRawMarkdown($note), 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
        ]);
    }

    public function downloadRaw(Request $request, string $path): StreamedResponse
    {
        try {
            $note = $this->commonplace->readNote($path, $request->user());
        } catch (ModelNotFoundException) {
            abort(404);
        } catch (AuthorizationException) {
            abort(403);
        }

        $rawContent = $this->buildRawMarkdown($note);
        $filename = Str::afterLast($note->path, '/').'.md';

        return response()->streamDownload(function () use ($rawContent): void {
            echo $rawContent;
        }, $filename, [
            'Content-Type' => 'text/markdown; charset=UTF-8',
        ]);
    }

    public function create(): View
    {
        return view('commonplace::create');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'path' => ['required', 'string'],
            'content' => ['required', 'string'],
            'tags' => ['sometimes', 'string'],
            'visibility' => ['sometimes', 'string', Rule::in(Visibility::values())],
        ]);

        $tags = isset($validated['tags'])
            ? array_values(array_filter(array_map('trim', explode(',', $validated['tags']))))
            : [];

        $note = $this->commonplace->createNote(
            path: $validated['path'],
            content: $validated['content'],
            tags: $tags,
            visibility: $validated['visibility'] ?? 'private',
            owner: $request->user(),
        );

        return redirect()
            ->route('commonplace.show', ['path' => $note->path])
            ->with('success', 'Note created successfully.');
    }

    public function edit(Request $request, string $path): View
    {
        try {
            $note = $this->commonplace->readNote($path, $request->user());
        } catch (ModelNotFoundException) {
            abort(404);
        } catch (AuthorizationException) {
            abort(403);
        }

        return view('commonplace::edit', [
            'note' => $note,
        ]);
    }

    public function update(Request $request, string $path): RedirectResponse
    {
        $validated = $request->validate([
            'content' => ['sometimes', 'string'],
            'tags' => ['sometimes', 'string'],
            'visibility' => ['sometimes', 'string', Rule::in(Visibility::values())],
            'new_path' => ['sometimes', 'string'],
        ]);

        $data = [];

        if ($request->has('content')) {
            $data['content'] = $validated['content'];
        }

        if ($request->has('tags')) {
            $data['tags'] = array_values(array_filter(array_map('trim', explode(',', $validated['tags']))));
        }

        if ($request->has('visibility')) {
            $data['visibility'] = $validated['visibility'];
        }

        if ($request->has('new_path')) {
            $data['new_path'] = $validated['new_path'];
        }

        try {
            $note = $this->commonplace->updateNote($path, $data, $request->user());
        } catch (ModelNotFoundException) {
            abort(404);
        } catch (AuthorizationException) {
            abort(403);
        }

        return redirect()
            ->route('commonplace.show', ['path' => $note->path])
            ->with('success', 'Note updated successfully.');
    }

    public function destroy(Request $request, string $path): RedirectResponse
    {
        try {
            $this->commonplace->deleteNote($path, $request->user());
        } catch (ModelNotFoundException) {
            abort(404);
        } catch (AuthorizationException) {
            abort(403);
        }

        return redirect()
            ->route('commonplace.index')
            ->with('success', 'Note deleted successfully.');
    }

    public function history(Request $request, string $path): View
    {
        $user = $request->user();

        try {
            $versions = $this->commonplace->getHistory($path, $user);
        } catch (AuthorizationException) {
            abort(403);
        }

        $note = Note::where('path', $path)->first();

        if ($versions->isEmpty() && $note === null) {
            abort(404);
        }

        return view('commonplace::history.index', [
            'path' => $path,
            'note' => $note,
            'versions' => $versions,
            'breadcrumbs' => $this->buildBreadcrumbs($path),
        ]);
    }

    public function historyVersion(Request $request, string $path, int $version): View
    {
        $user = $request->user();

        try {
            $versions = $this->commonplace->getHistory($path, $user);
        } catch (AuthorizationException) {
            abort(403);
        }

        $noteVersion = $versions->firstWhere('id', $version);

        if (! $noteVersion instanceof NoteVersion) {
            abort(404);
        }

        $note = Note::where('path', $path)->first();

        return view('commonplace::history.show', [
            'path' => $path,
            'note' => $note,
            'version' => $noteVersion,
            'renderedContent' => $this->markdown->renderNote($noteVersion->content),
            'breadcrumbs' => $this->buildBreadcrumbs($path),
        ]);
    }

    private function browseFolder(string $folder, Request $request): View
    {
        $result = $this->noteBrowser->browse($request->user(), $folder);
        $view = $folder === '' ? 'commonplace::index' : 'commonplace::browse';

        return view($view, [
            'folder' => $folder,
            'notes' => $result['notes'],
            'subfolders' => $result['subfolders'],
            'breadcrumbs' => $this->buildBreadcrumbs($folder),
        ]);
    }

    private function journal(Request $request, int $year, int $month, ?string $selectedDate): View
    {
        return view(
            'commonplace::journal',
            $this->journalCalendar->buildMonth($request->user(), $year, $month, $selectedDate),
        );
    }

    private function buildBreadcrumbs(string $path): array
    {
        if ($path === '') {
            return [];
        }

        $segments = explode('/', $path);
        $breadcrumbs = [];
        $builtPath = '';

        foreach ($segments as $segment) {
            $builtPath = $builtPath === '' ? $segment : $builtPath.'/'.$segment;
            $breadcrumbs[] = [
                'label' => $segment,
                'url' => route('commonplace.show', ['path' => $builtPath]),
            ];
        }

        return $breadcrumbs;
    }

    private function buildRawMarkdown(Note $note): string
    {
        $url = route('commonplace.show', ['path' => $note->path]);

        $ownerName = $note->owner?->getAttribute('name') ?? 'Unknown';

        $header = "# {$note->title}\n";
        $header .= "\n**Author:** {$ownerName}";
        $header .= "\n**Date:** {$note->updated_at?->format('F j, Y')}";
        $header .= "\n\n**URL:** {$url}\n";
        $header .= "\n---\n\n";

        return $header.$note->content;
    }
}
