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
use NonConvexLabs\Commonplace\Enums\SemanticSearchScope;
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

            $rawScope = $request->get('scope');
            $scope = $rawScope === null || $rawScope === ''
                ? SemanticSearchScope::Mine
                : (SemanticSearchScope::tryFrom((string) $rawScope)
                    ?? throw new \InvalidArgumentException(
                        "Unknown scope '{$rawScope}'. Use one of: mine, public, accessible."
                    ));

            $results = $this->commonplace->getSuggestedLinks(
                path: $request->get('path'),
                user: $request->user(),
                limit: min((int) $limit, 20),
                scope: $scope,
            );

            $payload = ['suggestions' => $results];

            $warnings = $this->commonplace->lastSearchWarnings();

            if ($warnings !== []) {
                $payload['warnings'] = $warnings;
            }

            return Response::json($payload);
        } catch (\InvalidArgumentException $e) {
            return Response::error($e->getMessage());
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
            'scope' => $schema->string()
                ->description('Suggestion scope: "mine" (default — only from your notes, avoids cross-user dangling links), "public", or "accessible"')
                ->enum(['mine', 'public', 'accessible'])
                ->nullable(),
        ];
    }
}
