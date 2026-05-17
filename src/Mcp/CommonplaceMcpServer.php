<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Mcp;

use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
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
}
