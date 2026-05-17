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
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use NonConvexLabs\Commonplace\Services\Commonplace;

#[Description('Read a note by path, returning full markdown content, tags, visibility, and timestamps. Start sessions by reading "commonplace-guide" for organizational conventions.')]
#[IsReadOnly(true)]
class ReadNoteTool extends Tool
{
    public function __construct(private readonly Commonplace $commonplace) {}

    public function handle(Request $request): Response
    {
        try {
            $note = $this->commonplace->readNote(
                path: $request->get('path'),
                user: $request->user(),
            );

            return Response::json([
                'path' => $note->path,
                'title' => $note->title,
                'content' => $note->content,
                'visibility' => $note->visibility,
                'tags' => $note->tags->pluck('name')->all(),
                'updated_at' => $note->updated_at->toIso8601String(),
            ]);
        } catch (AuthorizationException|ModelNotFoundException) {
            // Collapse "inaccessible" and "missing" into the same response
            // to prevent path enumeration. See docs/mcp-tools.md#read-note-tool.
            return Response::error('Note not found.');
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'path' => $schema->string('Virtual path of the note to read'),
        ];
    }
}
