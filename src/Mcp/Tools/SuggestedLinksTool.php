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

#[Description('Suggest notes that should be linked to a given note based on semantic similarity (embedding distance). Only returns notes that are NOT already linked. Use to discover missing connections.')]
#[IsReadOnly(true)]
class SuggestedLinksTool extends Tool
{
    public function __construct(private readonly Commonplace $commonplace) {}

    public function handle(Request $request): Response
    {
        try {
            $limit = $request->get('limit') ?? 10;

            $results = $this->commonplace->getSuggestedLinks(
                path: $request->get('path'),
                user: $request->user(),
                limit: min((int) $limit, 20),
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
            'path' => $schema->string('Virtual path of the note to find suggestions for'),
            'limit' => $schema->number('Maximum number of suggestions (default 10, max 20)'),
        ];
    }
}
