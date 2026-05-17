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

#[Description('Retrieve version history for a note, showing content hashes, authors, and timestamps. Works for both active and deleted notes (versions are preserved after deletion). Useful for auditing changes or recovering previous content.')]
#[IsReadOnly(true)]
class HistoryTool extends Tool
{
    public function __construct(private readonly Commonplace $commonplace) {}

    public function handle(Request $request): Response
    {
        try {
            $versions = $this->commonplace->getHistory(
                path: $request->get('path'),
                user: $request->user(),
            );

            return Response::json($versions->map(fn ($version) => [
                'id' => $version->id,
                'content_hash' => $version->content_hash,
                'changed_by' => $version->author?->name,
                'created_at' => $version->created_at->toIso8601String(),
            ])->all());
        } catch (AuthorizationException|ModelNotFoundException) {
            // Collapse "inaccessible" and "missing" into the same response
            // to prevent path enumeration. See docs/mcp-tools.md#history-tool.
            return Response::error('Note not found.');
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'path' => $schema->string('Virtual path of the note to retrieve history for'),
        ];
    }
}
