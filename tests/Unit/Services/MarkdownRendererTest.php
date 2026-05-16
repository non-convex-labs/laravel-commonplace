<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Tests\Unit\Services;

use LogicException;
use NonConvexLabs\Commonplace\Services\MarkdownRenderer;
use NonConvexLabs\Commonplace\Services\WikilinkParser;
use NonConvexLabs\Commonplace\Tests\TestCase;

// Fallback stub for the WikilinkParser class while chunk 2 lands in parallel.
// Once chunk 2 is merged, this conditional becomes a no-op because class_exists()
// triggers autoloading and finds the real implementation first.
if (! class_exists(WikilinkParser::class)) {
    eval(
        'namespace NonConvexLabs\\Commonplace\\Services;'
        .' class WikilinkParser {'
        .'   public function resolveTarget(string $target): ?object { return null; }'
        .' }'
    );
}

class MarkdownRendererTest extends TestCase
{
    private MarkdownRenderer $renderer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->renderer = new MarkdownRenderer(new WikilinkParser);
    }

    public function test_renders_headings(): void
    {
        $html = $this->renderer->render("# H1\n\n## H2\n\n### H3");

        $this->assertStringContainsString('<h1>H1</h1>', $html);
        $this->assertStringContainsString('<h2>H2</h2>', $html);
        $this->assertStringContainsString('<h3>H3</h3>', $html);
    }

    public function test_renders_bold_and_italic(): void
    {
        $html = $this->renderer->render('**bold** and *italic*');

        $this->assertStringContainsString('<strong>bold</strong>', $html);
        $this->assertStringContainsString('<em>italic</em>', $html);
    }

    public function test_renders_unordered_lists(): void
    {
        $html = $this->renderer->render("- one\n- two\n- three");

        $this->assertStringContainsString('<ul>', $html);
        $this->assertStringContainsString('<li>one</li>', $html);
        $this->assertStringContainsString('<li>two</li>', $html);
        $this->assertStringContainsString('<li>three</li>', $html);
    }

    public function test_renders_ordered_lists(): void
    {
        $html = $this->renderer->render("1. one\n2. two");

        $this->assertStringContainsString('<ol>', $html);
        $this->assertStringContainsString('<li>one</li>', $html);
    }

    public function test_renders_fenced_code_blocks(): void
    {
        $html = $this->renderer->render("```php\necho 'hi';\n```");

        $this->assertStringContainsString('<pre>', $html);
        $this->assertStringContainsString('<code', $html);
        $this->assertStringContainsString("echo 'hi';", $html);
    }

    public function test_renders_inline_code(): void
    {
        $html = $this->renderer->render('Use `array_map()` for mapping.');

        $this->assertStringContainsString('<code>array_map()</code>', $html);
    }

    public function test_renders_links(): void
    {
        $html = $this->renderer->render('[Example](https://example.com)');

        $this->assertStringContainsString('<a href="https://example.com">Example</a>', $html);
    }

    public function test_renders_images(): void
    {
        $html = $this->renderer->render('![alt text](https://example.com/img.png)');

        $this->assertStringContainsString('<img src="https://example.com/img.png"', $html);
        $this->assertStringContainsString('alt="alt text"', $html);
    }

    public function test_renders_blockquote(): void
    {
        $html = $this->renderer->render('> quoted text');

        $this->assertStringContainsString('<blockquote>', $html);
        $this->assertStringContainsString('quoted text', $html);
    }

    public function test_renders_horizontal_rule(): void
    {
        $html = $this->renderer->render("before\n\n---\n\nafter");

        $this->assertStringContainsString('<hr', $html);
    }

    public function test_gfm_renders_tables(): void
    {
        $markdown = <<<'MD'
            | Col A | Col B |
            | ----- | ----- |
            | one   | two   |
            MD;

        $html = $this->renderer->render($markdown);

        $this->assertStringContainsString('<table>', $html);
        $this->assertStringContainsString('<th>Col A</th>', $html);
        $this->assertStringContainsString('<td>one</td>', $html);
    }

    public function test_gfm_renders_strikethrough(): void
    {
        $html = $this->renderer->render('This is ~~struck~~ text.');

        $this->assertStringContainsString('<del>struck</del>', $html);
    }

    public function test_gfm_renders_task_lists(): void
    {
        $html = $this->renderer->render("- [ ] todo\n- [x] done");

        $this->assertStringContainsString('type="checkbox"', $html);
        $this->assertStringContainsString('checked', $html);
    }

    public function test_gfm_autolinks_bare_urls(): void
    {
        $html = $this->renderer->render('Visit https://example.com for info.');

        $this->assertStringContainsString('<a href="https://example.com">', $html);
    }

    public function test_gfm_disallows_unsafe_html_tags(): void
    {
        $html = $this->renderer->render('<script>alert(1)</script>');

        $this->assertStringNotContainsString('<script>', $html);
    }

    public function test_footnote_extension_renders_references(): void
    {
        $markdown = "Some text with a footnote.[^1]\n\n[^1]: This is the footnote body.";

        $html = $this->renderer->render($markdown);

        $this->assertStringContainsString('class="footnote', $html);
        $this->assertStringContainsString('This is the footnote body.', $html);
    }

    public function test_render_handles_empty_input(): void
    {
        $this->assertSame('', $this->renderer->render(''));
    }

    public function test_render_handles_whitespace_only_input(): void
    {
        $this->assertSame('', trim($this->renderer->render("   \n\n   ")));
    }

    public function test_render_handles_malformed_markdown_gracefully(): void
    {
        $html = $this->renderer->render('**unterminated bold [oops](');

        $this->assertNotSame('', $html);
        $this->assertIsString($html);
    }

    public function test_render_handles_very_long_input(): void
    {
        $line = str_repeat('lorem ipsum dolor sit amet ', 50);
        $markdown = str_repeat($line."\n\n", 200);

        $html = $this->renderer->render($markdown);

        $this->assertIsString($html);
        $this->assertGreaterThan(strlen($markdown) / 2, strlen($html));
    }

    public function test_render_converts_mermaid_block_to_language_mermaid_code(): void
    {
        $markdown = "before\n\n```mermaid\ngraph TD\n  A-->B\n```\n\nafter";

        $html = $this->renderer->render($markdown);

        $this->assertStringContainsString('<pre><code class="language-mermaid">', $html);
        $this->assertStringContainsString('graph TD', $html);
        $this->assertStringContainsString('A--&gt;B', $html);
        $this->assertStringContainsString('before', $html);
        $this->assertStringContainsString('after', $html);
    }

    public function test_render_escapes_html_inside_mermaid_block(): void
    {
        $markdown = "```mermaid\nA --> \"<b>label</b>\"\n```";

        $html = $this->renderer->render($markdown);

        $this->assertStringContainsString('&lt;b&gt;label&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('<b>label</b>', $html);
    }

    public function test_render_handles_multiple_mermaid_blocks(): void
    {
        $markdown = "```mermaid\ngraph A\n```\n\nbetween\n\n```mermaid\ngraph B\n```";

        $html = $this->renderer->render($markdown);

        $this->assertStringContainsString('graph A', $html);
        $this->assertStringContainsString('graph B', $html);
        $this->assertSame(2, substr_count($html, 'language-mermaid'));
    }

    public function test_render_lesson_strips_matching_h1_title(): void
    {
        $html = $this->renderer->renderLesson("# What Is an Agent?\n\nBody copy.", 'What Is an Agent?');

        $this->assertStringNotContainsString('<h1>What Is an Agent?</h1>', $html);
        $this->assertStringContainsString('Body copy.', $html);
    }

    public function test_render_lesson_strips_matching_h2_title(): void
    {
        $html = $this->renderer->renderLesson("## What Is an Agent?\n\nBody copy.", 'What Is an Agent?');

        $this->assertStringNotContainsString('<h2>What Is an Agent?</h2>', $html);
        $this->assertStringContainsString('Body copy.', $html);
    }

    public function test_render_lesson_title_match_is_case_insensitive(): void
    {
        $html = $this->renderer->renderLesson("# what is an agent?\n\nBody.", 'What Is an Agent?');

        $this->assertStringNotContainsString('<h1>', $html);
        $this->assertStringContainsString('Body.', $html);
    }

    public function test_render_lesson_does_not_strip_h3_or_deeper(): void
    {
        $html = $this->renderer->renderLesson("### Title\n\nBody.", 'Title');

        $this->assertStringContainsString('<h3>Title</h3>', $html);
    }

    public function test_render_lesson_only_strips_leading_heading(): void
    {
        $markdown = "Intro paragraph.\n\n# Title\n\nBody.";

        $html = $this->renderer->renderLesson($markdown, 'Title');

        $this->assertStringContainsString('<h1>Title</h1>', $html);
        $this->assertStringContainsString('Intro paragraph.', $html);
    }

    public function test_render_lesson_leaves_non_matching_heading_intact(): void
    {
        $html = $this->renderer->renderLesson("# Different Title\n\nBody.", 'Original Title');

        $this->assertStringContainsString('<h1>Different Title</h1>', $html);
    }

    public function test_render_vault_note_throws_until_chunk_4(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(
            'Wikilink resolution to Note records will be wired in chunk 4 (Commonplace service)'
        );

        $this->renderer->renderVaultNote("---\ntitle: Foo\n---\n\n# Foo\n\nLink to [[Other Note]].");
    }
}
