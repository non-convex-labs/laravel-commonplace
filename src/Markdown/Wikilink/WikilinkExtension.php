<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Markdown\Wikilink;

use League\CommonMark\Environment\EnvironmentBuilderInterface;
use League\CommonMark\Extension\ExtensionInterface;
use NonConvexLabs\Commonplace\Contracts\WikilinkResolver;

final class WikilinkExtension implements ExtensionInterface
{
    public function __construct(
        private readonly WikilinkResolver $resolver,
    ) {}

    public function register(EnvironmentBuilderInterface $environment): void
    {
        // Higher priority than the built-in OpenBracketParser (priority 0)
        // so `[[…]]` is consumed as a wikilink before the bracket parser
        // tries to parse it as a standard `[link](url)`.
        $environment->addInlineParser(new WikilinkInlineParser, priority: 100);
        $environment->addRenderer(WikilinkNode::class, new WikilinkRenderer($this->resolver));
    }
}
