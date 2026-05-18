<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Http\Controllers;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use NonConvexLabs\Commonplace\Models\Note;
use NonConvexLabs\Commonplace\Services\MarkdownRenderer;

/**
 * Public-read controller — serves notes with `visibility = 'public'`
 * to unauthenticated visitors. The corresponding route group is
 * registered separately so it can run under different middleware
 * (typically just `web`, no `auth`).
 *
 * Editing / listing / search are deliberately not exposed; this is
 * a read-only window onto explicitly-marked public notes.
 */
class PublicNoteController extends Controller
{
    public function __construct(
        private readonly MarkdownRenderer $markdown,
    ) {}

    public function show(Request $request, string $path): View
    {
        $note = $this->resolvePublicNote($path);

        return view('commonplace::public.show', [
            'note' => $note,
            'renderedContent' => $this->markdown->renderNote($note->content),
        ]);
    }

    public function showRaw(Request $request, string $path): Response
    {
        $note = $this->resolvePublicNote($path);

        return response((string) $note->content, 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
        ]);
    }

    private function resolvePublicNote(string $path): Note
    {
        try {
            return Note::query()
                ->where('path', $path)
                ->where('visibility', 'public')
                ->firstOrFail();
        } catch (ModelNotFoundException) {
            // 404, not 403 — leaking "this note exists but isn't public"
            // would let an attacker enumerate the private vault.
            abort(404);
        }
    }
}
