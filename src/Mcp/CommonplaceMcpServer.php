<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Mcp;

use Illuminate\Database\LostConnectionException;
use Illuminate\Database\QueryException;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use Laravel\Mcp\Server\Exceptions\JsonRpcException;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Server\Transport\JsonRpcRequest;
use Laravel\Mcp\Server\Transport\JsonRpcResponse;
use NonConvexLabs\Commonplace\Mcp\Tools\BacklinksTool;
use NonConvexLabs\Commonplace\Mcp\Tools\CreateNoteTool;
use NonConvexLabs\Commonplace\Mcp\Tools\DeleteNoteTool;
use NonConvexLabs\Commonplace\Mcp\Tools\EditNoteTool;
use NonConvexLabs\Commonplace\Mcp\Tools\HistoryTool;
use NonConvexLabs\Commonplace\Mcp\Tools\HubNotesTool;
use NonConvexLabs\Commonplace\Mcp\Tools\ListTool;
use NonConvexLabs\Commonplace\Mcp\Tools\MoveTool;
use NonConvexLabs\Commonplace\Mcp\Tools\NeighborhoodTool;
use NonConvexLabs\Commonplace\Mcp\Tools\OrphanNotesTool;
use NonConvexLabs\Commonplace\Mcp\Tools\ReadNoteTool;
use NonConvexLabs\Commonplace\Mcp\Tools\SearchTool;
use NonConvexLabs\Commonplace\Mcp\Tools\SemanticSearchTool;
use NonConvexLabs\Commonplace\Mcp\Tools\ShortestPathTool;
use NonConvexLabs\Commonplace\Mcp\Tools\SuggestedLinksTool;
use NonConvexLabs\Commonplace\Mcp\Tools\UpdateNoteTool;
use PDOException;
use Throwable;

#[Name('commonplace')]
#[Version('0.1.0')]
#[Instructions(<<<'INSTRUCTIONS'
Personal knowledge commonplace for storing and searching markdown notes.

## Getting Started

Read the `commonplace-guide` note first with `read-note-tool` (path: "commonplace-guide") to understand folder structure, naming conventions, and tagging strategy.

## Key Concepts

- **Paths** are virtual (not filesystem). Use lowercase kebab-case with folder prefixes: `projects/ncl/roadmap`, `references/laravel-eloquent`, `journal/2026-03-08`.
- **Wikilinks** (`[[path]]`) connect notes bidirectionally. Use `backlinks-tool` to discover inbound links.
- **Tags** provide cross-cutting categorization independent of folders. Common tags: `ncl`, `ai`, `guide`, `decision`, `reference`, `draft`.
- **Frontmatter** (optional YAML between `---` fences) can set `title` and `tags`.
- **Visibility**: `private` (default) or `public`. Sharing with specific other users is granted per-note via the `Share` model — not a visibility value.

## Workflow

1. **Start** with `list-tool` to see existing notes before creating new ones.
2. **Search** with `semantic-search-tool` first (default). Fall back to `search-tool` only for exact substring matching.
3. **Prefer editing** with `edit-note-tool` for targeted changes instead of rewriting entire notes.
4. **Prefer updating** existing notes over creating duplicates.
5. **Use `move-tool`** instead of delete + recreate to preserve history and update wikilinks.
INSTRUCTIONS)]
class CommonplaceMcpServer extends Server
{
    public int $defaultPaginationLength = 50;

    protected array $tools = [
        CreateNoteTool::class,
        ReadNoteTool::class,
        UpdateNoteTool::class,
        EditNoteTool::class,
        DeleteNoteTool::class,
        ListTool::class,
        SearchTool::class,
        SemanticSearchTool::class,
        BacklinksTool::class,
        MoveTool::class,
        HistoryTool::class,
        NeighborhoodTool::class,
        ShortestPathTool::class,
        HubNotesTool::class,
        OrphanNotesTool::class,
        SuggestedLinksTool::class,
    ];

    /**
     * Wrap `tools/call` dispatch so any unhandled exception inside a tool
     * handler is surfaced as a JSON-RPC `result.isError` envelope (per
     * S-AI-25 in docs/scenarios/ai-agent.md) instead of a transport-level
     * error or HTTP 500. Protocol-level errors (parse errors, unknown
     * method, malformed params) still propagate as `JsonRpcException` so
     * the framework can return a proper JSON-RPC `error` response.
     *
     * @return iterable<JsonRpcResponse>|JsonRpcResponse
     *
     * @throws JsonRpcException
     */
    protected function runMethodHandle(JsonRpcRequest $request, ServerContext $context): iterable|JsonRpcResponse
    {
        if ($request->method !== 'tools/call') {
            return parent::runMethodHandle($request, $context);
        }

        try {
            return parent::runMethodHandle($request, $context);
        } catch (JsonRpcException $jsonRpcException) {
            // Protocol-level error (missing/invalid params, unknown tool, etc.).
            // Keep as a JSON-RPC error so the transport can surface it correctly.
            throw $jsonRpcException;
        } catch (Throwable $throwable) {
            // Tool handler crashed (e.g. QueryException, RuntimeException).
            // Report so operators still see the stack, then convert to a
            // JSON-RPC tool error envelope (HTTP 200, isError: true).
            report($throwable);

            return $this->toolErrorEnvelope($request, $throwable);
        }
    }

    /**
     * Build a `tools/call` response envelope that mirrors the shape
     * produced by Laravel\Mcp\Server\Methods\CallTool when a tool returns
     * Response::error(...). The agent client gets a single text content
     * item with the message and `isError: true`.
     */
    protected function toolErrorEnvelope(JsonRpcRequest $request, Throwable $throwable): JsonRpcResponse
    {
        $message = $this->publicMessageFor($throwable);

        return JsonRpcResponse::result($request->id, [
            'content' => [
                Response::error($message)->content()->toArray(),
            ],
            'isError' => true,
        ]);
    }

    /**
     * Sanitise an exception message for the wire. The full Throwable is
     * still available to operators via report(); only the MCP envelope's
     * text is redacted.
     *
     * Database-layer exceptions are redacted because their formatted
     * messages embed connection metadata (Host, Port, Database), the
     * parameterized SQL, and PDO's `DETAIL:` row data:
     *
     * - `QueryException` (#115) — preserve the SQLSTATE category so
     *   callers can still tell unique-violation from deadlock from
     *   connection error, but drop everything else.
     * - `LostConnectionException` (#118) — extends `LogicException`,
     *   message text typically embeds the connection name. Replace with
     *   a generic "Database connection lost." string.
     * - bare `PDOException` (#118) — covers `DeadlockException` (which
     *   extends `PDOException` directly, NOT `QueryException`) and any
     *   raw PDO errors that escape Laravel's wrapping. Replace with a
     *   generic "Database error." string.
     *
     * Other Throwables (RuntimeException, InvalidArgumentException, etc.)
     * pass through verbatim — tools rely on their messages for actionable
     * caller-facing errors. Tool authors must keep file paths, internal
     * URLs, env values, cache keys, etc. out of those messages; the MCP
     * envelope cannot defend against arbitrary stdlib exception types
     * without breaking the tool-error contract. See [#118] for the
     * broader allowlist-sanitiser design discussion.
     */
    protected function publicMessageFor(Throwable $throwable): string
    {
        if ($throwable instanceof QueryException) {
            $sqlState = (string) $throwable->getCode();

            return $sqlState !== ''
                ? "Database error: SQLSTATE[{$sqlState}]"
                : 'Database error.';
        }

        if ($throwable instanceof LostConnectionException) {
            return 'Database connection lost.';
        }

        if ($throwable instanceof PDOException) {
            return 'Database error.';
        }

        return $throwable->getMessage() !== ''
            ? $throwable->getMessage()
            : 'The tool failed to complete the request.';
    }
}
