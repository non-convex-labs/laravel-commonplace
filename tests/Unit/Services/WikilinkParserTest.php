<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Tests\Unit\Services;

use NonConvexLabs\Commonplace\Services\WikilinkParser;
use NonConvexLabs\Commonplace\Tests\TestCase;

class WikilinkParserTest extends TestCase
{
    private WikilinkParser $parser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->parser = new WikilinkParser;
    }

    public function test_extracts_single_link(): void
    {
        $links = $this->parser->extractLinks('See [[My Note]] for details.');

        $this->assertCount(1, $links);
        $this->assertSame(['target' => 'My Note', 'display' => 'My Note'], $links[0]);
    }

    public function test_extracts_multiple_links_in_one_document(): void
    {
        $content = 'First [[Alpha]] and then [[Beta]] and finally [[Gamma]].';

        $links = $this->parser->extractLinks($content);

        $this->assertCount(3, $links);
        $this->assertSame('Alpha', $links[0]['target']);
        $this->assertSame('Beta', $links[1]['target']);
        $this->assertSame('Gamma', $links[2]['target']);
    }

    public function test_extracts_alias_with_pipe(): void
    {
        $links = $this->parser->extractLinks('Reference [[Target Note|Friendly Display]] here.');

        $this->assertCount(1, $links);
        $this->assertSame('Target Note', $links[0]['target']);
        $this->assertSame('Friendly Display', $links[0]['display']);
    }

    public function test_uses_basename_as_default_display_for_path_links(): void
    {
        $links = $this->parser->extractLinks('See [[folder/sub/note]] please.');

        $this->assertCount(1, $links);
        $this->assertSame('folder/sub/note', $links[0]['target']);
        $this->assertSame('note', $links[0]['display']);
    }

    public function test_alias_overrides_basename_default_for_path_links(): void
    {
        $links = $this->parser->extractLinks('[[folder/note|Custom Label]]');

        $this->assertCount(1, $links);
        $this->assertSame('folder/note', $links[0]['target']);
        $this->assertSame('Custom Label', $links[0]['display']);
    }

    public function test_returns_empty_array_when_no_links_present(): void
    {
        $links = $this->parser->extractLinks('Plain markdown with no wikilinks.');

        $this->assertSame([], $links);
    }

    public function test_returns_empty_array_for_empty_content(): void
    {
        $this->assertSame([], $this->parser->extractLinks(''));
    }

    public function test_trims_whitespace_around_target_and_display(): void
    {
        $links = $this->parser->extractLinks('[[  spaced target  |  spaced display  ]]');

        $this->assertSame('spaced target', $links[0]['target']);
        $this->assertSame('spaced display', $links[0]['display']);
    }

    public function test_skips_wikilinks_inside_fenced_code_blocks(): void
    {
        $content = <<<'MD'
Some text.

```
This [[CodeBlockLink]] is inside a fence.
```

Outside [[OutsideLink]].
MD;

        $links = $this->parser->extractLinks($content);

        $this->assertCount(1, $links);
        $this->assertSame('OutsideLink', $links[0]['target']);
    }

    public function test_skips_wikilinks_inside_tilde_fenced_code_blocks(): void
    {
        $content = <<<'MD'
~~~
Inside [[Tilde]].
~~~

After [[Plain]].
MD;

        $links = $this->parser->extractLinks($content);

        $this->assertCount(1, $links);
        $this->assertSame('Plain', $links[0]['target']);
    }

    public function test_skips_wikilinks_inside_fenced_code_block_with_info_string(): void
    {
        $content = <<<'MD'
Intro.

```text
Sample [[ExampleLink]] usage.
```

After [[Real]].
MD;

        $links = $this->parser->extractLinks($content);

        $this->assertCount(1, $links);
        $this->assertSame('Real', $links[0]['target']);
    }

    public function test_skips_wikilinks_inside_inline_code(): void
    {
        $links = $this->parser->extractLinks('Use `[[InlineLink]]` syntax and then [[Real]].');

        $this->assertCount(1, $links);
        $this->assertSame('Real', $links[0]['target']);
    }

    public function test_skips_wikilinks_inside_double_backtick_inline_code(): void
    {
        $links = $this->parser->extractLinks('Use ``[[InlineLink]]`` here and [[Real]] there.');

        $this->assertCount(1, $links);
        $this->assertSame('Real', $links[0]['target']);
    }

    public function test_extracts_link_following_an_escaped_pair_of_brackets(): void
    {
        $content = 'Backslash before \[[Escaped]] is still matched.';

        $links = $this->parser->extractLinks($content);

        $this->assertCount(1, $links);
        $this->assertSame('Escaped', $links[0]['target']);
    }

    public function test_ignores_single_bracket_pairs(): void
    {
        $links = $this->parser->extractLinks('A [single] bracket pair is not a wikilink.');

        $this->assertSame([], $links);
    }

    public function test_extracts_link_with_only_pipe_and_empty_display(): void
    {
        $links = $this->parser->extractLinks('[[target|]]');

        $this->assertSame('target', $links[0]['target']);
        $this->assertSame('', $links[0]['display']);
    }
}
