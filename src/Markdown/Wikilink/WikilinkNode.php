<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Markdown\Wikilink;

use League\CommonMark\Node\Inline\AbstractInline;

final class WikilinkNode extends AbstractInline
{
    public function __construct(
        public readonly string $target,
        public readonly string $display,
    ) {
        parent::__construct();
    }
}
