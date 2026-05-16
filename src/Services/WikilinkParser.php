<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Services;

use NonConvexLabs\Commonplace\Models\Note;

class WikilinkParser
{
    public function extractLinks(string $content): array
    {
        if (! preg_match_all('/\[\[([^\]]+)\]\]/', $content, $matches)) {
            return [];
        }

        $links = [];

        foreach ($matches[1] as $raw) {
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
