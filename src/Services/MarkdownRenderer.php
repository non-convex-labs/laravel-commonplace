<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Services;

use Illuminate\Contracts\Container\Container;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\ExtensionInterface;
use League\CommonMark\MarkdownConverter;
use NonConvexLabs\Commonplace\Support\Highlight\Bash\BashLanguage;
use RuntimeException;
use Tempest\Highlight\CommonMark\HighlightExtension;
use Tempest\Highlight\Highlighter;

class MarkdownRenderer
{
    /**
     * Memoized converter. Built once on first render; reused thereafter.
     * The Environment + Highlighter + extension stack are expensive to
     * (re)build; under a 100-note page this matters. Extenders are frozen
     * once this is built (see `Commonplace::extendMarkdown`).
     */
    private ?MarkdownConverter $converter = null;

    public function __construct(
        private readonly Container $container,
        private readonly Commonplace $commonplace,
    ) {}

    public function renderLesson(string $markdown, string $lessonTitle): string
    {
        // NOTE: this strip operates on raw markdown, before extensions
        // run. A custom heading-syntax extension won't be seen here.
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
        return $this->render($this->stripFrontmatter($content));
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

        $html = $this->converter()->convert($markdown)->getContent();

        foreach ($mermaidBlocks as $index => $content) {
            // Tolerant of attribute injection on the wrapping <p> — a
            // consumer extension may add classes / data-attrs there.
            $html = preg_replace(
                '/<p\b[^>]*>MERMAID_BLOCK_'.$index.'_PLACEHOLDER<\/p>/',
                '<pre><code class="language-mermaid">'.htmlspecialchars($content, ENT_QUOTES, 'UTF-8').'</code></pre>',
                $html,
            );
        }

        return $html;
    }

    private function converter(): MarkdownConverter
    {
        return $this->converter ??= $this->buildConverter();
    }

    private function buildConverter(): MarkdownConverter
    {
        $environment = new Environment([
            'html_input' => 'allow',
            'allow_unsafe_links' => false,
        ]);

        // Core CommonMark is non-removable.
        $environment->addExtension(new CommonMarkCoreExtension);

        foreach ($this->resolveExtensions() as $extension) {
            $environment->addExtension($extension);
        }

        // Highlight is special — it requires a constructed Highlighter,
        // not a class string. Kept out of the config-driven list. Users
        // who want to disable it can do so by binding a stub via the
        // container or removing this branch in a fork.
        if ((bool) config('commonplace.markdown.highlight.enabled', true)) {
            $highlighter = new Highlighter;
            $highlighter->addLanguage(new BashLanguage);
            $environment->addExtension(new HighlightExtension($highlighter));
        }

        // Runtime extenders run LAST, so they win on conflicting node
        // renderers and can see all prior extensions in the Environment.
        foreach ($this->commonplace->registeredMarkdownExtenders() as $callback) {
            $callback($environment);
        }

        return new MarkdownConverter($environment);
    }

    /**
     * @return iterable<ExtensionInterface>
     */
    private function resolveExtensions(): iterable
    {
        // Config is user-controlled; treat each entry as mixed and
        // validate at the call site so a typo throws a clear error
        // rather than booting with a half-broken markdown pipeline.
        $configured = (array) config('commonplace.markdown.extensions', []);

        foreach ($configured as $entry) {
            if ($entry instanceof ExtensionInterface) {
                yield $entry;

                continue;
            }

            if (is_string($entry)) {
                $resolved = $this->container->make($entry);

                if (! $resolved instanceof ExtensionInterface) {
                    throw new RuntimeException(sprintf(
                        'Configured markdown extension "%s" did not resolve to an ExtensionInterface (got %s). '
                        .'Check commonplace.markdown.extensions.',
                        $entry,
                        get_debug_type($resolved),
                    ));
                }

                yield $resolved;

                continue;
            }

            throw new RuntimeException(sprintf(
                'commonplace.markdown.extensions entries must be class strings or ExtensionInterface instances; got %s.',
                get_debug_type($entry),
            ));
        }
    }

    private function stripFrontmatter(string $content): string
    {
        if (preg_match('/\A---\s*\n.*?---\s*\n?(.*)\z/s', $content, $matches)) {
            return $matches[1];
        }

        return $content;
    }
}
