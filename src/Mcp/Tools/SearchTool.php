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

#[Description('Fallback text search — use semantic-search-tool first. ILIKE substring matching across titles and content. Use only when you need exact keyword/phrase matching or when semantic search returns no results.')]
#[IsReadOnly(true)]
class SearchTool extends Tool
{
    public function __construct(private readonly Commonplace $commonplace) {}

    public function handle(Request $request): Response
    {
        $notes = $this->commonplace->searchNotes(
            query: $request->get('query'),
            user: $request->user(),
        );

        return Response::json($notes->map(fn ($note) => [
            'path' => $note->path,
            'title' => $note->title,
            'excerpt' => mb_substr($note->content, 0, 200),
            'updated_at' => $note->updated_at->toIso8601String(),
        ])->all());
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string('Search term (minimum 2 characters)'),
        ];
    }
}
