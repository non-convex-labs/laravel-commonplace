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

#[Description('Find notes with zero wikilinks — no incoming or outgoing connections. These are isolated notes that may benefit from being linked to related content.')]
#[IsReadOnly(true)]
class OrphanNotesTool extends Tool
{
    public function __construct(private readonly Commonplace $commonplace) {}

    public function handle(Request $request): Response
    {
        $notes = $this->commonplace->getOrphanNotes(user: $request->user());

        return Response::json($notes->map(fn ($note) => [
            'path' => $note->path,
            'title' => $note->title,
            'tags' => $note->tags->pluck('name')->all(),
            'updated_at' => $note->updated_at->toIso8601String(),
        ])->all());
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
