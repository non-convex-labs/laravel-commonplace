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
use NonConvexLabs\Commonplace\Models\Link;
use NonConvexLabs\Commonplace\Models\Note;
use NonConvexLabs\Commonplace\Services\Commonplace;

class GraphController extends Controller
{
    public function __construct(
        private readonly Commonplace $commonplace,
    ) {}

    public function graph(): View
    {
        return view('commonplace::graph');
    }

    public function graphApi(Request $request): JsonResponse
    {
        $user = $request->user();

        $notes = Note::accessibleBy($user)
            ->with('tags')
            ->get();

        $noteIdMap = $notes->keyBy('id');

        $links = Link::whereIn('source_note_id', $noteIdMap->keys())
            ->whereIn('target_note_id', $noteIdMap->keys())
            ->get();

        $nodes = $notes->map(fn (Note $note) => [
            'id' => $note->path,
            'title' => $note->title,
            'folder' => str_contains($note->path, '/') ? Str::before($note->path, '/') : '',
            'tags' => $note->tags->pluck('name')->all(),
            'updated_at' => $note->updated_at?->toIso8601String(),
        ])->values()->all();

        $edges = $links->map(fn (Link $link) => [
            'source' => $noteIdMap[$link->source_note_id]->path,
            'target' => $noteIdMap[$link->target_note_id]->path,
        ])->values()->all();

        return response()->json([
            'nodes' => $nodes,
            'edges' => $edges,
        ]);
    }

    public function neighborhood(Request $request, string $path): JsonResponse
    {
        $maxHops = max(1, min(5, (int) $request->input('hops', 2)));

        try {
            $neighborhood = $this->commonplace->getNeighborhood(
                $path,
                $maxHops,
                $request->user(),
            );
        } catch (ModelNotFoundException) {
            abort(404);
        } catch (AuthorizationException) {
            // #123: collapse inaccessible into 404 so the neighborhood
            // JSON endpoint can't be used to enumerate paths the caller
            // can't read.
            abort(404);
        }

        return response()->json([
            'path' => $path,
            'max_hops' => $maxHops,
            'neighbors' => $neighborhood,
        ]);
    }
}
