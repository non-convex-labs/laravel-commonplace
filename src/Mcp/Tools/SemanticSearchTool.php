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

#[Description('Primary search tool — use this by default. Semantic search using AI embeddings for meaning-based matching. Finds conceptually related notes even when exact words differ. Use natural language queries (e.g. "how we handle authentication" rather than "auth"). Fall back to search-tool only when you need exact substring matching.')]
#[IsReadOnly(true)]
class SemanticSearchTool extends Tool
{
    public function __construct(private readonly Commonplace $commonplace) {}

    public function handle(Request $request): Response
    {
        try {
            $results = $this->commonplace->semanticSearch(
                query: $request->get('query'),
                user: $request->user(),
            );

            return Response::json($results->map(fn ($note) => [
                'path' => $note->path,
                'title' => $note->title,
                'excerpt' => mb_substr($note->content, 0, 200),
                'distance' => $note->distance ?? null,
                'updated_at' => $note->updated_at->toIso8601String(),
            ])->all());
        } catch (\RuntimeException $e) {
            return Response::error($e->getMessage());
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string('Natural language query to search for semantically similar notes'),
        ];
    }
}
