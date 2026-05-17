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

#[Description('Find the shortest chain of wikilinks connecting two notes. Returns the ordered list of notes forming the path, or null if no connection exists within 10 hops. Use to discover how two topics relate.')]
#[IsReadOnly(true)]
class ShortestPathTool extends Tool
{
    public function __construct(private readonly Commonplace $commonplace) {}

    public function handle(Request $request): Response
    {
        try {
            $path = $this->commonplace->getShortestPath(
                fromPath: $request->get('from_path'),
                toPath: $request->get('to_path'),
                user: $request->user(),
            );

            if ($path === null) {
                return Response::json(['connected' => false, 'path' => []]);
            }

            return Response::json(['connected' => true, 'path' => $path]);
        } catch (AuthorizationException|ModelNotFoundException) {
            // Collapse "inaccessible" and "missing" into the same response
            // to prevent path enumeration. See docs/mcp-tools.md#shortest-path-tool.
            return Response::error('One or both notes not found.');
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'from_path' => $schema->string('Virtual path of the starting note'),
            'to_path' => $schema->string('Virtual path of the destination note'),
        ];
    }
}
