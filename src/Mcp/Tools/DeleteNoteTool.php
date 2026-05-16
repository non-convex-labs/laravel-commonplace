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
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use NonConvexLabs\Commonplace\Services\Commonplace;

#[Description('Permanently delete a note. A final version snapshot is preserved in history and can be retrieved via history-tool. Consider move-tool or update-tool instead if the content should be preserved.')]
#[IsDestructive(true)]
class DeleteNoteTool extends Tool
{
    public function __construct(private readonly Commonplace $commonplace) {}

    public function handle(Request $request): Response
    {
        try {
            $path = $request->get('path');

            $this->commonplace->deleteNote(
                path: $path,
                user: $request->user(),
            );

            return Response::text("Note deleted: {$path}");
        } catch (AuthorizationException $e) {
            return Response::error($e->getMessage());
        } catch (ModelNotFoundException) {
            return Response::error('Note not found.');
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'path' => $schema->string('Virtual path of the note to delete'),
        ];
    }
}
