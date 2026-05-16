<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Mcp\Tools;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use NonConvexLabs\Commonplace\Services\Commonplace;

#[Description('Move or rename a note to a new path. Preserves version history and asynchronously updates [[wikilinks]] in all referencing notes. Prefer this over delete + recreate.')]
class MoveTool extends Tool
{
    public function __construct(private readonly Commonplace $commonplace) {}

    public function handle(Request $request): Response
    {
        try {
            $note = $this->commonplace->moveNote(
                fromPath: $request->get('from_path'),
                toPath: $request->get('to_path'),
                user: $request->user(),
            );

            return Response::json([
                'path' => $note->path,
                'title' => $note->title,
                'visibility' => $note->visibility,
                'tags' => $note->tags->pluck('name')->all(),
                'updated_at' => $note->updated_at->toIso8601String(),
            ]);
        } catch (AuthorizationException $e) {
            return Response::error($e->getMessage());
        } catch (ModelNotFoundException) {
            return Response::error('Note not found.');
        } catch (\InvalidArgumentException $e) {
            return Response::error($e->getMessage());
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'from_path' => $schema->string('Current path of the note'),
            'to_path' => $schema->string('New path for the note'),
        ];
    }
}
