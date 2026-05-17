<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Contracts;

use NonConvexLabs\Commonplace\Markdown\Wikilink\ResolvedWikilink;

/**
 * Resolves a `[[wikilink]]` target into a renderable link.
 *
 * Bind your own implementation in a service provider to point wikilinks
 * at different models or external URLs. Returning null produces a
 * "broken" link (the renderer falls back to the configured route prefix).
 */
interface WikilinkResolver
{
    public function resolve(string $target): ?ResolvedWikilink;
}
