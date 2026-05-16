<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use NonConvexLabs\Commonplace\Services\Commonplace;

#[Description('List notes with optional filters. Returns path, title, tags, and timestamps — not content (use read-note-tool for that). Call with no arguments to see all notes. Filter by folder prefix (e.g. "projects/ncl"), tag name, or visibility level.')]
#[IsReadOnly(true)]
class ListTool extends Tool
{
    public function __construct(private readonly Commonplace $commonplace) {}

    public function handle(Request $request): Response
    {
        $notes = $this->commonplace->listNotes(
            folder: $request->get('folder'),
            tag: $request->get('tag'),
            visibility: $request->get('visibility'),
            user: $request->user(),
        );

        return Response::json($notes->map(fn ($note) => [
            'path' => $note->path,
            'title' => $note->title,
            'visibility' => $note->visibility,
            'tags' => $note->tags->pluck('name')->all(),
            'updated_at' => $note->updated_at->toIso8601String(),
        ])->all());
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'folder' => $schema->string('Filter by folder prefix, e.g. projects/ncl')->nullable(),
            'tag' => $schema->string('Filter by tag name')->nullable(),
            'visibility' => $schema->string('Filter by visibility: private, shared, or public')->nullable(),
        ];
    }
}
