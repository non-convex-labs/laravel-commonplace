<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Str;
use NonConvexLabs\Commonplace\Models\Note;

class NoteBrowser
{
    public function browse(?Authenticatable $user, string $folder): array
    {
        $query = Note::accessibleBy($user)->with('tags');

        if ($folder !== '') {
            $query->inFolder($folder);
        }

        $allNotes = $query->orderBy('path')->get();

        $notes = collect();
        $subfolders = [];

        foreach ($allNotes as $note) {
            $relativePath = $folder !== '' ? Str::after($note->path, $folder.'/') : $note->path;

            if (! str_contains($relativePath, '/')) {
                $notes->push($note);

                continue;
            }

            $subfolder = Str::before($relativePath, '/');
            $subfolders[$subfolder] = ($subfolders[$subfolder] ?? 0) + 1;
        }

        ksort($subfolders);

        return [
            'notes' => $notes,
            'subfolders' => $subfolders,
        ];
    }
}
