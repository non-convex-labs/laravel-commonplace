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

#[Description('Create a new markdown note. Path uses virtual folder structure (e.g. "projects/ncl/roadmap", "references/laravel-eloquent"). Content is markdown, optionally with YAML frontmatter between --- fences. Use list-tool first to check for existing notes and avoid duplicates.')]
class CreateNoteTool extends Tool
{
    public function __construct(private readonly Commonplace $commonplace) {}

    public function handle(Request $request): Response
    {
        try {
            $note = $this->commonplace->createNote(
                path: $request->get('path'),
                content: $request->get('content'),
                tags: $request->get('tags', []),
                visibility: $request->get('visibility', 'private'),
                owner: $request->user(),
            );

            return Response::json([
                'path' => $note->path,
                'title' => $note->title,
                'visibility' => $note->visibility,
                'tags' => $note->tags->pluck('name')->all(),
                'created_at' => $note->created_at->toIso8601String(),
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
            'path' => $schema->string('Virtual path for the note, e.g. projects/ncl/roadmap'),
            'content' => $schema->string('Markdown content, optionally with YAML frontmatter'),
            'tags' => $schema->array('Tag names to assign to the note', $schema->string('Tag name'))->nullable(),
            'visibility' => $schema->string()
                ->description('Visibility level: '.Visibility::describe().' (default: private)')
                ->nullable(),
        ];
    }
}
