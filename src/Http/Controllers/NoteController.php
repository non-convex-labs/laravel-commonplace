<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Http\Controllers;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\View\View;
use NonConvexLabs\Commonplace\Models\Note;
use NonConvexLabs\Commonplace\Services\Commonplace;
use NonConvexLabs\Commonplace\Services\MarkdownRenderer;
use Symfony\Component\HttpFoundation\StreamedResponse;

class NoteController extends Controller
{
    public function __construct(
        private readonly Commonplace $commonplace,
        private readonly MarkdownRenderer $markdown,
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
            'visibility' => ['sometimes', 'string', 'in:private,shared,public'],
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
            'visibility' => ['sometimes', 'string', 'in:private,shared,public'],
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

    private function browseFolder(string $folder, Request $request): View
    {
        $user = $request->user();

        $query = Note::accessibleBy($user)->with('tags');

        if ($folder !== '') {
            $query->inFolder($folder);
        }

        $allNotes = $query->orderBy('path')->get();

        $directNotes = collect();
        $subfolders = [];

        foreach ($allNotes as $note) {
            $relativePath = $folder !== '' ? Str::after($note->path, $folder.'/') : $note->path;

            if (! str_contains($relativePath, '/')) {
                $directNotes->push($note);
            } else {
                $subfolder = Str::before($relativePath, '/');
                $subfolders[$subfolder] = ($subfolders[$subfolder] ?? 0) + 1;
            }
        }

        ksort($subfolders);

        $view = $folder === '' ? 'commonplace::index' : 'commonplace::browse';

        return view($view, [
            'folder' => $folder,
            'notes' => $directNotes,
            'subfolders' => $subfolders,
            'breadcrumbs' => $this->buildBreadcrumbs($folder),
        ]);
    }

    private function journal(Request $request, int $year, int $month, ?string $selectedDate): View
    {
        $user = $request->user();
        $year = max(2000, min(2100, $year));
        $month = max(1, min(12, $month));

        $monthStart = Carbon::createFromDate($year, $month, 1);

        $prefix = sprintf('journal/%04d-%02d-', $year, $month);
        $journalNotes = Note::accessibleBy($user)
            ->where('path', 'like', $prefix.'%')
            ->with('tags')
            ->orderBy('path')
            ->get();

        $notesByDay = [];
        foreach ($journalNotes as $note) {
            $relative = Str::after($note->path, 'journal/');
            if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $relative, $matches)) {
                $notesByDay[$matches[1]][] = $note;
            }
        }

        $selectedNotes = isset($notesByDay[$selectedDate])
            ? collect($notesByDay[$selectedDate])
            : collect();

        $prevMonth = $monthStart->copy()->subMonth();
        $nextMonth = $monthStart->copy()->addMonth();

        $calendarDays = $this->buildCalendarDays($year, $month, $monthStart, $notesByDay, $selectedDate);

        return view('commonplace::journal', [
            'year' => $year,
            'month' => $month,
            'monthName' => $monthStart->format('F'),
            'calendarDays' => $calendarDays,
            'selectedDate' => $selectedDate,
            'selectedNotes' => $selectedNotes,
            'prevYear' => $prevMonth->year,
            'prevMonthNum' => $prevMonth->month,
            'nextYear' => $nextMonth->year,
            'nextMonthNum' => $nextMonth->month,
        ]);
    }

    private function buildCalendarDays(int $year, int $month, Carbon $monthStart, array $notesByDay, ?string $selectedDate): array
    {
        $daysInMonth = $monthStart->daysInMonth;
        $startDayOfWeek = $monthStart->dayOfWeek;
        $totalCells = (int) ceil(($startDayOfWeek + $daysInMonth) / 7) * 7;
        $today = date('Y-m-d');

        $days = [];
        $dayCounter = 1;

        for ($cell = 0; $cell < $totalCells; $cell++) {
            $isValidDay = $cell >= $startDayOfWeek && $dayCounter <= $daysInMonth;

            if (! $isValidDay) {
                $days[] = null;

                continue;
            }

            $dateStr = sprintf('%04d-%02d-%02d', $year, $month, $dayCounter);
            $noteCount = isset($notesByDay[$dateStr]) ? count($notesByDay[$dateStr]) : 0;

            $days[] = [
                'day' => $dayCounter,
                'date' => $dateStr,
                'hasNotes' => $noteCount > 0,
                'noteCount' => $noteCount,
                'isSelected' => $dateStr === $selectedDate,
                'isToday' => $dateStr === $today,
            ];

            $dayCounter++;
        }

        return $days;
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
