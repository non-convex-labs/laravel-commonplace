<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Markdown\Wikilink;

use League\CommonMark\Parser\Inline\InlineParserInterface;
use League\CommonMark\Parser\Inline\InlineParserMatch;
use League\CommonMark\Parser\InlineParserContext;

final class WikilinkInlineParser implements InlineParserInterface
{
    public function getMatchDefinition(): InlineParserMatch
    {
        // [[target]] or [[target|display]]. Newlines are not allowed,
        // matching the legacy regex.
        return InlineParserMatch::regex('\[\[[^\]\n]+\]\]');
    }

    public function parse(InlineParserContext $inlineContext): bool
    {
        $cursor = $inlineContext->getCursor();
        [$full] = $inlineContext->getMatches();

        $cursor->advanceBy(strlen($full));

        $inner = substr($full, 2, -2);
        $parts = explode('|', $inner, 2);
        $target = trim($parts[0]);

        if ($target === '') {
            return false;
        }

        $display = isset($parts[1]) ? trim($parts[1]) : $this->defaultDisplay($target);

        $inlineContext->getContainer()->appendChild(new WikilinkNode($target, $display));

        return true;
    }

    private function defaultDisplay(string $target): string
    {
        return str_contains($target, '/') ? basename($target) : $target;
    }
}
