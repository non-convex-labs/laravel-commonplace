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

#[Description('Find the most connected notes in the commonplace, ranked by total inbound + outbound wikilinks. Hub notes are central to the knowledge graph and often represent key concepts, projects, or people.')]
#[IsReadOnly(true)]
class HubNotesTool extends Tool
{
    public function __construct(private readonly Commonplace $commonplace) {}

    public function handle(Request $request): Response
    {
        $limit = $request->get('limit') ?? 20;

        $results = $this->commonplace->getHubNotes(
            user: $request->user(),
            limit: min((int) $limit, 50),
        );

        return Response::json($results);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'limit' => $schema->number('Maximum number of results (default 20, max 50)'),
        ];
    }
}
