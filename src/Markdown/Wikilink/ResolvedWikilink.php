<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Markdown\Wikilink;

/**
 * Result of resolving a wikilink target. Model-agnostic so consumers
 * can wire a resolver that points at non-Note models (or external URLs).
 */
final class ResolvedWikilink
{
    public function __construct(
        public readonly string $href,
        public readonly ?string $title = null,
    ) {}
}
