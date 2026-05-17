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

#[Description('Find all notes that link to the given note via [[wikilinks]]. Useful for discovering how a concept connects to other notes and for understanding note importance by inbound link count.')]
#[IsReadOnly(true)]
class BacklinksTool extends Tool
{
    public function __construct(private readonly Commonplace $commonplace) {}

    public function handle(Request $request): Response
    {
        try {
            $notes = $this->commonplace->getBacklinks(
                path: $request->get('path'),
                user: $request->user(),
            );

            return Response::json($notes->map(fn ($note) => [
                'path' => $note->path,
                'title' => $note->title,
            ])->all());
        } catch (AuthorizationException|ModelNotFoundException) {
            // Collapse "inaccessible" and "missing" into the same response
            // to prevent path enumeration. See docs/mcp-tools.md#backlinks-tool.
            return Response::error('Note not found.');
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'path' => $schema->string('Virtual path of the note to find backlinks for'),
        ];
    }
}
