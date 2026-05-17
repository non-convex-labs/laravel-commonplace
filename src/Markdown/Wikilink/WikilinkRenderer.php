<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Markdown\Wikilink;

use InvalidArgumentException;
use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;
use League\CommonMark\Util\HtmlElement;
use NonConvexLabs\Commonplace\Contracts\WikilinkResolver;

final class WikilinkRenderer implements NodeRendererInterface
{
    public function __construct(
        private readonly WikilinkResolver $resolver,
    ) {}

    public function render(Node $node, ChildNodeRendererInterface $childRenderer): HtmlElement
    {
        if (! $node instanceof WikilinkNode) {
            throw new InvalidArgumentException(sprintf(
                'Incompatible node type "%s" passed to WikilinkRenderer.',
                $node::class,
            ));
        }

        $resolved = $this->resolver->resolve($node->target);

        $prefix = '/'.ltrim((string) config('commonplace.routes.prefix', 'commonplace'), '/');

        if ($resolved !== null) {
            return new HtmlElement('a', [
                'href' => $resolved->href,
                'class' => 'vault-link',
            ], $node->display);
        }

        return new HtmlElement('a', [
            'href' => $prefix.'/'.ltrim($node->target, '/'),
            'class' => 'vault-link vault-link-broken',
        ], $node->display);
    }
}
