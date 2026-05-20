<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use NonConvexLabs\Commonplace\Models\Link;
use NonConvexLabs\Commonplace\Models\Note;
use NonConvexLabs\Commonplace\Services\WikilinkParser;
use NonConvexLabs\Commonplace\Support\MarkdownCodeRanges;
use Throwable;

/**
 * Rewrites every `[[wikilink]]` pointing at a moved note so the link
 * text reflects the new path. Dispatched from `Commonplace::moveNote`
 * via `DB::afterCommit`, off the request path.
 *
 * Source of truth is the `commonplace_links` table — the rows already
 * record exactly which textual `target_path` each source note used to
 * reach the moved note. Replacing those literal strings in source
 * content is precise: no risk of a regex-text search clobbering an
 * unrelated occurrence, no dependency on `WikilinkParser::resolveTarget`
 * trailing-segment fallback (whose `->first()` ordering is
 * non-deterministic — see issue #54). For the same reason, this job
 * writes link rows directly instead of calling `syncWikilinks` to
 * rebuild them.
 *
 * Three legitimate wikilink forms are preserved:
 *   - `[[old/path]]`               → `[[new/path]]`
 *   - `[[old/path|Display label]]` → `[[new/path|Display label]]`
 *   - `[[basename]]` resolved by trailing-segment match → `[[new/path]]`
 *
 * Wikilinks inside fenced or inline code are left untouched — the
 * renderer treats them as literal sample text, and so do we.
 * Anchor handling (`[[a/b#heading]]`) remains out of scope here.
 * See issue #54.
 *
 * Recovery: if the queue worker is down and the job never runs,
 * link rows still resolve correctly because the moved note's
 * `target_note_id` still points at the same row — the row's
 * `target_path` is stale text, but the FK is intact. `commonplace:relink`
 * + `commonplace:doctor` surface anything that did drift to NULL.
 */
#[Tries(3)]
#[Backoff([10, 30, 120])]
class UpdateWikilinksJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly int $movedNoteId,
        public readonly string $fromPath,
        public readonly string $toPath,
    ) {
        // User-facing: the agent just renamed a note and expects
        // backlinks to follow. Pin off the default queue (and off the
        // slow backup / embeddings queues) so a stuck external
        // provider doesn't delay the rewrite. See styleguide §6.
        $this->onQueue('commonplace-wikilinks');
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Commonplace UpdateWikilinksJob failed', [
            'moved_note_id' => $this->movedNoteId,
            'from_path' => $this->fromPath,
            'to_path' => $this->toPath,
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
        ]);
    }

    public function handle(): void
    {
        if ($this->fromPath === $this->toPath) {
            return;
        }

        $links = Link::query()
            ->where('target_note_id', $this->movedNoteId)
            ->where('target_path', '!=', $this->toPath)
            ->get(['id', 'source_note_id', 'target_path']);

        $bySource = $links->groupBy('source_note_id');

        foreach ($bySource as $sourceId => $sourceLinks) {
            $sourceNote = Note::find($sourceId);

            if ($sourceNote === null) {
                continue;
            }

            $oldTargets = $sourceLinks->pluck('target_path')->unique()->all();
            $rewritten = $this->rewriteContent($sourceNote->content, $oldTargets, $this->toPath);

            if ($rewritten !== $sourceNote->content) {
                $sourceNote->content = $rewritten;
                $sourceNote->content_hash = hash('sha256', $rewritten);
                $sourceNote->indexed_at = null;
                $sourceNote->save();
            }

            Link::where('source_note_id', $sourceId)
                ->whereIn('target_path', $oldTargets)
                ->delete();

            Link::updateOrCreate(
                [
                    'source_note_id' => $sourceId,
                    'target_path' => $this->toPath,
                ],
                [
                    'target_note_id' => $this->movedNoteId,
                ],
            );
        }

        // Orphan rows that typed the old path verbatim now resolve to the
        // moved note. The path text in source content is stale until the
        // next syncWikilinks pass — but the FK is correct, so navigation
        // works and `commonplace:doctor` won't flag them.
        Link::query()
            ->where('target_path', $this->fromPath)
            ->whereNull('target_note_id')
            ->update(['target_note_id' => $this->movedNoteId]);
    }

    /**
     * @param  list<string>  $oldTargets
     */
    private function rewriteContent(string $content, array $oldTargets, string $toPath): string
    {
        // Offset-capturing scan, then filter occurrences that fall
        // inside fenced or inline code. Sidesteps preg_quote — paths
        // with regex metacharacters (`references/c++/notes`,
        // `meetings/2025-05-17`) never enter a pattern.
        if (! preg_match_all(WikilinkParser::PATTERN, $content, $matches, PREG_OFFSET_CAPTURE)) {
            return $content;
        }

        $codeRanges = MarkdownCodeRanges::find($content);

        // Walk matches in reverse so earlier offsets stay valid as we
        // splice replacements in.
        for ($i = count($matches[0]) - 1; $i >= 0; $i--) {
            [$full, $offset] = $matches[0][$i];

            if (MarkdownCodeRanges::contains($codeRanges, $offset)) {
                continue;
            }

            $inner = $matches[1][$i][0];
            $parts = explode('|', $inner, 2);
            $target = trim($parts[0]);

            if (! in_array($target, $oldTargets, true)) {
                continue;
            }

            $replacement = isset($parts[1])
                ? '[['.$toPath.'|'.$parts[1].']]'
                : '[['.$toPath.']]';

            $content = substr_replace($content, $replacement, $offset, strlen($full));
        }

        return $content;
    }
}
