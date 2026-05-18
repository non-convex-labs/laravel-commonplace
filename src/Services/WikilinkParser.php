<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Services;

use NonConvexLabs\Commonplace\Contracts\WikilinkResolver;
use NonConvexLabs\Commonplace\Markdown\Wikilink\ResolvedWikilink;
use NonConvexLabs\Commonplace\Models\Note;
use NonConvexLabs\Commonplace\Support\MarkdownCodeRanges;

class WikilinkParser implements WikilinkResolver
{
    /**
     * Single source of truth for `[[wikilink]]` recognition. Same shape
     * as the inline parser uses so DB-sync, rendering, and the
     * move-rewrite job all agree on what counts as a wikilink. Capture
     * group 1 is the raw inner text (may contain `target|alias`).
     */
    public const PATTERN = '/\[\[([^\]\n]+)\]\]/';

    public function resolve(string $target): ?ResolvedWikilink
    {
        $note = $this->resolveTarget($target);

        if ($note === null) {
            return null;
        }

        $prefix = '/'.ltrim((string) config('commonplace.routes.prefix', 'commonplace'), '/');

        return new ResolvedWikilink(
            href: $prefix.'/'.ltrim($note->path, '/'),
            title: $note->title,
        );
    }

    public function extractLinks(string $content): array
    {
        if (! preg_match_all(self::PATTERN, $content, $matches, PREG_OFFSET_CAPTURE)) {
            return [];
        }

        $codeRanges = MarkdownCodeRanges::find($content);
        $links = [];

        foreach ($matches[0] as $i => $full) {
            if (MarkdownCodeRanges::contains($codeRanges, $full[1])) {
                continue;
            }

            $raw = $matches[1][$i][0];
            $parts = explode('|', $raw, 2);
            $target = trim($parts[0]);
            $display = isset($parts[1]) ? trim($parts[1]) : $this->defaultDisplay($target);

            $links[] = ['target' => $target, 'display' => $display];
        }

        return $links;
    }

    public function resolveTarget(string $target): ?Note
    {
        $note = Note::where('path', $target)->first();

        if ($note) {
            return $note;
        }

        $note = Note::whereRaw('LOWER(title) = ?', [mb_strtolower($target)])->first();

        if ($note) {
            return $note;
        }

        $segment = basename($target);

        return Note::where(function ($query) use ($segment) {
            $query->where('path', 'like', '%/'.$segment)
                ->orWhere('path', $segment);
        })->first();
    }

    private function defaultDisplay(string $target): string
    {
        if (str_contains($target, '/')) {
            return basename($target);
        }

        return $target;
    }
}
