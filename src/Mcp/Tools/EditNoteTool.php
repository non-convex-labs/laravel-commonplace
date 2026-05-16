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
use NonConvexLabs\Commonplace\Services\Commonplace;

#[Description('Make a selective text replacement in a note without rewriting the entire content. Finds old_string in the note and replaces it with new_string. The old_string must match exactly (including whitespace and indentation). If old_string appears more than once, the edit fails unless replace_all is true. Prefer this over update-note-tool when changing a specific section of a note.')]
class EditNoteTool extends Tool
{
    public function __construct(private readonly Commonplace $commonplace) {}

    public function handle(Request $request): Response
    {
        try {
            $note = $this->commonplace->editNote(
                path: $request->get('path'),
                oldString: $request->get('old_string'),
                newString: $request->get('new_string'),
                replaceAll: (bool) $request->get('replace_all', false),
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
        } catch (\InvalidArgumentException $e) {
            return $this->editError($e->getMessage(), $request);
        }
    }

    private function editError(string $message, Request $request): Response
    {
        try {
            $note = $this->commonplace->readNote($request->get('path'), $request->user());

            return Response::error(
                $message
                ."\n\n--- current note content ---\n"
                .$note->content
            );
        } catch (\Throwable) {
            return Response::error($message);
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'path' => $schema->string('Virtual path of the note to edit'),
            'old_string' => $schema->string('The exact text to find and replace. Must match exactly, including whitespace and indentation.'),
            'new_string' => $schema->string('The replacement text. Use an empty string to delete the matched text.'),
            'replace_all' => $schema->boolean('Replace all occurrences of old_string (default false). When false, the edit fails if old_string appears more than once.')->nullable(),
        ];
    }
}
