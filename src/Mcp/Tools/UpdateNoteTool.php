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
use NonConvexLabs\Commonplace\Enums\Visibility;
use NonConvexLabs\Commonplace\Services\Commonplace;

#[Description('Update an existing note. Only provided fields are changed; omitted fields remain unchanged. Prefer this over delete + recreate to preserve version history. Read the note first to avoid overwriting content.')]
class UpdateNoteTool extends Tool
{
    public function __construct(private readonly Commonplace $commonplace) {}

    public function handle(Request $request): Response
    {
        try {
            $data = array_filter([
                'content' => $request->get('content'),
                'tags' => $request->get('tags'),
                'visibility' => $request->get('visibility'),
                'new_path' => $request->get('new_path'),
            ], fn ($value) => $value !== null);

            $note = $this->commonplace->updateNote(
                path: $request->get('path'),
                data: $data,
                user: $request->user(),
            );

            return Response::json([
                'path' => $note->path,
                'title' => $note->title,
                'content' => $note->content,
                'visibility' => $note->visibility,
                'tags' => $note->tags->pluck('name')->all(),
                'updated_at' => $note->updated_at->toIso8601String(),
            ]);
        } catch (AuthorizationException $e) {
            return Response::error($e->getMessage());
        } catch (ModelNotFoundException) {
            return Response::error('Note not found.');
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'path' => $schema->string('Virtual path of the note to update'),
            'content' => $schema->string('New markdown content')->nullable(),
            'tags' => $schema->array('New tag names (replaces existing tags)', $schema->string('Tag name'))->nullable(),
            'visibility' => $schema->string()
                ->description('New visibility: '.Visibility::describe())
                ->nullable(),
            'new_path' => $schema->string('New path to rename/move the note to')->nullable(),
        ];
    }
}
