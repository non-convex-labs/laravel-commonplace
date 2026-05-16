<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Services;

use ElGigi\CommonMarkEmoji\EmojiExtension;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\Footnote\FootnoteExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\MarkdownConverter;
use NonConvexLabs\Commonplace\Support\Highlight\Bash\BashLanguage;
use Tempest\Highlight\CommonMark\HighlightExtension;
use Tempest\Highlight\Highlighter;

class MarkdownRenderer
{
    private readonly MarkdownConverter $converter;

    public function __construct(
        private readonly WikilinkParser $wikilinkParser,
    ) {
        $highlighter = new Highlighter;
        $highlighter->addLanguage(new BashLanguage);

        $environment = new Environment([
            'html_input' => 'allow',
            'allow_unsafe_links' => false,
        ]);

        $environment->addExtension(new CommonMarkCoreExtension);
        $environment->addExtension(new GithubFlavoredMarkdownExtension);
        $environment->addExtension(new FootnoteExtension);
        $environment->addExtension(new EmojiExtension);
        $environment->addExtension(new HighlightExtension($highlighter));

        $this->converter = new MarkdownConverter($environment);
    }

    public function renderLesson(string $markdown, string $lessonTitle): string
    {
        $markdown = preg_replace(
            '/\A\s*#{1,2}\s+'.preg_quote($lessonTitle, '/').'\s*\n+/iu',
            '',
            $markdown,
            1
        );

        return $this->render($markdown);
    }

    public function renderNote(string $content): string
    {
        $content = $this->stripFrontmatter($content);
        $content = $this->convertWikilinksToHtml($content);

        return $this->render($content);
    }

    public function render(string $markdown): string
    {
        $mermaidBlocks = [];

        $markdown = preg_replace_callback(
            '/^```mermaid\s*\n(.*?)^```\s*$/ms',
            function (array $matches) use (&$mermaidBlocks): string {
                $index = count($mermaidBlocks);
                $mermaidBlocks[$index] = $matches[1];

                return "\n\nMERMAID_BLOCK_{$index}_PLACEHOLDER\n\n";
            },
            $markdown
        );

        $html = $this->converter->convert($markdown)->getContent();

        foreach ($mermaidBlocks as $index => $content) {
            $html = str_replace(
                "<p>MERMAID_BLOCK_{$index}_PLACEHOLDER</p>",
                '<pre><code class="language-mermaid">'.htmlspecialchars($content, ENT_QUOTES, 'UTF-8').'</code></pre>',
                $html
            );
        }

        return $html;
    }

    private function stripFrontmatter(string $content): string
    {
        if (preg_match('/\A---\s*\n.*?---\s*\n?(.*)\z/s', $content, $matches)) {
            return $matches[1];
        }

        return $content;
    }

    private function convertWikilinksToHtml(string $content): string
    {
        return preg_replace_callback('/\[\[([^\]]+)\]\]/', function (array $matches): string {
            $raw = $matches[1];
            $parts = explode('|', $raw, 2);
            $target = trim($parts[0]);
            $display = isset($parts[1]) ? trim($parts[1]) : $this->wikilinkDisplayText($target);

            $resolved = $this->wikilinkParser->resolveTarget($target);
            $escapedDisplay = htmlspecialchars($display, ENT_QUOTES, 'UTF-8');

            $prefix = rtrim((string) config('commonplace.routes.prefix', 'commonplace'), '/');

            if ($resolved) {
                $href = '/'.$prefix.'/'.ltrim($resolved->path, '/');
                $escapedHref = htmlspecialchars($href, ENT_QUOTES, 'UTF-8');

                return '<a href="'.$escapedHref.'" class="vault-link">'.$escapedDisplay.'</a>';
            }

            $href = '/'.$prefix.'/'.ltrim($target, '/');
            $escapedHref = htmlspecialchars($href, ENT_QUOTES, 'UTF-8');

            return '<a href="'.$escapedHref.'" class="vault-link vault-link-broken">'.$escapedDisplay.'</a>';
        }, $content);
    }

    private function wikilinkDisplayText(string $target): string
    {
        if (str_contains($target, '/')) {
            return basename($target);
        }

        return $target;
    }
}
