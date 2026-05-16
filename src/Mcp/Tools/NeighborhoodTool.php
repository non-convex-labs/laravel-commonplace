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

#[Description('Find all notes connected to a given note within N hops via wikilinks (bidirectional). Returns notes grouped by distance. Use to explore a topic cluster or understand how a concept connects to the broader commonplace.')]
#[IsReadOnly(true)]
class NeighborhoodTool extends Tool
{
    public function __construct(private readonly Commonplace $commonplace) {}

    public function handle(Request $request): Response
    {
        try {
            $maxHops = $request->get('max_hops') ?? 2;

            $results = $this->commonplace->getNeighborhood(
                path: $request->get('path'),
                maxHops: min((int) $maxHops, 5),
                user: $request->user(),
            );

            return Response::json($results);
        } catch (AuthorizationException $e) {
            return Response::error($e->getMessage());
        } catch (ModelNotFoundException) {
            return Response::error('Note not found.');
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'path' => $schema->string('Virtual path of the note to explore from'),
            'max_hops' => $schema->number('Maximum link distance to traverse (1-5, default 2)'),
        ];
    }
}
