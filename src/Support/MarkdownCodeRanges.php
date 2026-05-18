<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Support;

/**
 * Locate byte ranges inside markdown that the renderer treats as code:
 * fenced code blocks (triple-backtick or tilde) and inline code spans.
 *
 * Used by the wikilink pipeline so a `[[link]]` that only appears as
 * example syntax inside code is neither extracted as a link row nor
 * rewritten by `UpdateWikilinksJob` on move. The renderer already
 * ignores these via CommonMark's block parser; this is the
 * equivalent for our regex-based passes.
 *
 * Known limitations (acceptable for now):
 *   - 4-space / tab-indented code blocks are not detected.
 *   - Inline code spans that wrap across a line break are detected
 *     per-line only; a `[[link]]` straddling such a wrap may still
 *     be seen as a wikilink.
 */
final class MarkdownCodeRanges
{
    /**
     * Return a list of `[start, end)` byte offsets in $content that
     * fall inside fenced code or inline code spans. Ranges are
     * sorted by start offset and never overlap.
     *
     * @return list<array{0: int, 1: int}>
     */
    public static function find(string $content): array
    {
        $ranges = [];
        $length = strlen($content);
        $offset = 0;

        $fenceChar = null;
        $fenceLen = 0;
        $fenceStart = 0;

        while ($offset < $length) {
            $eol = strpos($content, "\n", $offset);
            $lineEnd = $eol === false ? $length : $eol;
            $line = substr($content, $offset, $lineEnd - $offset);
            // CommonMark treats the line terminator as the \n; \r before
            // it is stripped from the logical line content.
            $logicalLine = str_ends_with($line, "\r") ? substr($line, 0, -1) : $line;

            if ($fenceChar !== null) {
                if (self::isFenceClose($logicalLine, $fenceChar, $fenceLen)) {
                    $ranges[] = [$fenceStart, $lineEnd];
                    $fenceChar = null;
                    $fenceLen = 0;
                }
            } elseif (($open = self::openingFence($logicalLine)) !== null) {
                $fenceChar = $open['char'];
                $fenceLen = $open['len'];
                $fenceStart = $offset;
            } else {
                foreach (self::inlineCodeSpans($line, $offset) as $span) {
                    $ranges[] = $span;
                }
            }

            if ($eol === false) {
                break;
            }

            $offset = $eol + 1;
        }

        // Unterminated fence — protect the rest of the document. This
        // matches CommonMark's behavior (the fence runs to EOF).
        if ($fenceChar !== null) {
            $ranges[] = [$fenceStart, $length];
        }

        return $ranges;
    }

    /**
     * Test whether $offset falls inside any of the ranges.
     *
     * @param  list<array{0: int, 1: int}>  $ranges
     */
    public static function contains(array $ranges, int $offset): bool
    {
        foreach ($ranges as [$start, $end]) {
            if ($offset >= $start && $offset < $end) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{char: string, len: int}|null
     */
    private static function openingFence(string $line): ?array
    {
        // ≤3 leading spaces allowed per CommonMark §4.5.
        if (! preg_match('/^ {0,3}(`{3,}|~{3,})([^`]*)$/u', $line, $m)) {
            return null;
        }

        $marker = $m[1];

        // Backtick fences may not contain backticks in the info string.
        if ($marker[0] === '`' && str_contains($m[2], '`')) {
            return null;
        }

        return ['char' => $marker[0], 'len' => strlen($marker)];
    }

    private static function isFenceClose(string $line, string $char, int $minLen): bool
    {
        $pattern = '/^ {0,3}'.preg_quote($char, '/').'{'.$minLen.',}\s*$/u';

        return (bool) preg_match($pattern, $line);
    }

    /**
     * Find inline code spans on a single line. Spans are byte ranges
     * relative to the document, offset by $lineOffset.
     *
     * Inline code: a run of N backticks, then content not containing
     * a run of exactly N backticks, then a closing run of N backticks.
     *
     * @return list<array{0: int, 1: int}>
     */
    private static function inlineCodeSpans(string $line, int $lineOffset): array
    {
        $spans = [];
        $len = strlen($line);
        $i = 0;

        while ($i < $len) {
            if ($line[$i] !== '`') {
                $i++;

                continue;
            }

            $runStart = $i;
            while ($i < $len && $line[$i] === '`') {
                $i++;
            }
            $runLen = $i - $runStart;

            // Look for a matching closing run.
            $searchFrom = $i;
            while ($searchFrom < $len) {
                $closeStart = strpos($line, str_repeat('`', $runLen), $searchFrom);

                if ($closeStart === false) {
                    break;
                }

                $closeEnd = $closeStart + $runLen;

                // Must not be part of a longer backtick run.
                if ($closeEnd < $len && $line[$closeEnd] === '`') {
                    $searchFrom = $closeEnd;

                    while ($searchFrom < $len && $line[$searchFrom] === '`') {
                        $searchFrom++;
                    }

                    continue;
                }

                $spans[] = [$lineOffset + $runStart, $lineOffset + $closeEnd];
                $i = $closeEnd;

                continue 2;
            }

            // No close found — the leading run isn't an opener.
        }

        return $spans;
    }
}
