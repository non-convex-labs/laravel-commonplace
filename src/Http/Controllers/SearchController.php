<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Http\Controllers;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Illuminate\View\View;
use NonConvexLabs\Commonplace\Models\Note;
use NonConvexLabs\Commonplace\Services\Commonplace;

class SearchController extends Controller
{
    public function __construct(
        private readonly Commonplace $commonplace,
    ) {}

    public function search(Request $request): View
    {
        $query = trim((string) $request->input('q'));
        $semantic = $request->boolean('semantic');
        $notes = collect();

        if ($query !== '') {
            $notes = $semantic
                ? $this->commonplace->semanticSearch($query, $request->user())
                : $this->commonplace->searchNotes($query, $request->user());
        }

        return view('commonplace::search', [
            'query' => $query,
            'notes' => $notes,
            'semantic' => $semantic,
        ]);
    }

    public function searchApi(Request $request): JsonResponse
    {
        $query = trim((string) $request->input('q'));

        if ($query === '' || mb_strlen($query) < 2) {
            return response()->json([]);
        }

        $notes = $this->commonplace->searchNotes($query, $request->user())
            ->map(fn (Note $note) => [
                'path' => $note->path,
                'title' => $note->title,
                'excerpt' => Str::limit($note->content, 150),
                'url' => route('commonplace.show', ['path' => $note->path]),
                'updated_at' => $note->updated_at?->toIso8601String(),
                'tags' => $note->tags->pluck('name'),
            ]);

        return response()->json($notes);
    }

    public function suggestedLinks(Request $request, string $path): JsonResponse
    {
        try {
            $suggestions = $this->commonplace->getSuggestedLinks(
                $path,
                $request->user(),
                (int) $request->input('limit', 10),
            );
        } catch (ModelNotFoundException) {
            abort(404);
        } catch (AuthorizationException) {
            // #123: collapse inaccessible into 404 so the suggested-links
            // endpoint can't be used to enumerate paths the caller can't
            // read.
            abort(404);
        }

        return response()->json($suggestions);
    }
}
