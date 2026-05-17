<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Tests\Unit\Services;

use NonConvexLabs\Commonplace\Services\FrontmatterParser;
use NonConvexLabs\Commonplace\Tests\TestCase;

class FrontmatterParserTest extends TestCase
{
    private FrontmatterParser $parser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->parser = new FrontmatterParser;
    }

    public function test_parses_valid_frontmatter_with_recognized_keys(): void
    {
        $content = <<<'MD'
---
title: My Note
visibility: public
tags:
  - alpha
  - beta
---
Body content here.
MD;

        $result = $this->parser->parse($content);

        $this->assertSame('My Note', $result['meta']['title']);
        $this->assertSame('public', $result['meta']['visibility']);
        $this->assertSame(['alpha', 'beta'], $result['meta']['tags']);
        $this->assertSame('Body content here.', $result['body']);
    }

    public function test_filters_unrecognized_keys_out_of_meta(): void
    {
        $content = <<<'MD'
---
title: T
author: Someone
random: value
---
Body
MD;

        $result = $this->parser->parse($content);

        $this->assertSame(['title' => 'T'], $result['meta']);
        $this->assertArrayNotHasKey('author', $result['meta']);
        $this->assertArrayNotHasKey('random', $result['meta']);
    }

    public function test_coerces_single_tag_string_into_array(): void
    {
        $content = <<<'MD'
---
tags: alpha
---
Body
MD;

        $result = $this->parser->parse($content);

        $this->assertSame(['alpha'], $result['meta']['tags']);
    }

    public function test_coerces_title_and_visibility_to_strings(): void
    {
        $content = <<<'MD'
---
title: 42
visibility: true
---
Body
MD;

        $result = $this->parser->parse($content);

        $this->assertSame('42', $result['meta']['title']);
        $this->assertIsString($result['meta']['visibility']);
    }

    public function test_returns_empty_meta_when_no_frontmatter_present(): void
    {
        $content = "Just some markdown\nwith no frontmatter.";

        $result = $this->parser->parse($content);

        $this->assertSame([], $result['meta']);
        $this->assertSame($content, $result['body']);
    }

    public function test_handles_empty_content(): void
    {
        $result = $this->parser->parse('');

        $this->assertSame([], $result['meta']);
        $this->assertSame('', $result['body']);
    }

    public function test_returns_original_content_when_yaml_is_malformed(): void
    {
        $content = <<<'MD'
---
title: "unterminated
tags: [bad, list
---
Body content
MD;

        $result = $this->parser->parse($content);

        $this->assertSame([], $result['meta']);
        $this->assertSame($content, $result['body']);
    }

    public function test_returns_empty_meta_when_frontmatter_has_no_closing_delimiter(): void
    {
        $content = <<<'MD'
---
title: Never Closed
tags: [a, b]

Body content with no closing fence.
MD;

        $result = $this->parser->parse($content);

        $this->assertSame([], $result['meta']);
        $this->assertSame($content, $result['body']);
    }

    public function test_ignores_dashed_separator_later_in_body(): void
    {
        $content = <<<'MD'
This document starts with text.

---

Then has a horizontal rule.
MD;

        $result = $this->parser->parse($content);

        $this->assertSame([], $result['meta']);
        $this->assertSame($content, $result['body']);
    }

    public function test_returns_empty_meta_when_yaml_parses_to_non_array_scalar(): void
    {
        $content = <<<'MD'
---
just a scalar string
---
Body content here.
MD;

        $result = $this->parser->parse($content);

        $this->assertSame([], $result['meta']);
        $this->assertSame('Body content here.', $result['body']);
    }

    public function test_preserves_multiline_body_after_frontmatter(): void
    {
        $content = <<<'MD'
---
title: T
---
Line one.

Line two.

Line three.
MD;

        $result = $this->parser->parse($content);

        $this->assertSame("Line one.\n\nLine two.\n\nLine three.", $result['body']);
    }

    public function test_parses_crlf_frontmatter_with_same_metadata_as_lf(): void
    {
        $lf = "---\ntitle: Cross Platform\nvisibility: public\ntags:\n  - alpha\n  - beta\n---\nBody content.";
        $crlf = str_replace("\n", "\r\n", $lf);

        $lfResult = $this->parser->parse($lf);
        $crlfResult = $this->parser->parse($crlf);

        $this->assertSame($lfResult['meta'], $crlfResult['meta']);
        $this->assertSame('Cross Platform', $crlfResult['meta']['title']);
        $this->assertSame('public', $crlfResult['meta']['visibility']);
        $this->assertSame(['alpha', 'beta'], $crlfResult['meta']['tags']);
    }

    public function test_parses_crlf_frontmatter_when_body_has_trailing_blank_line(): void
    {
        $lf = "---\ntitle: T\ntags:\n  - a\n---\n\nBody copy.";
        $crlf = str_replace("\n", "\r\n", $lf);

        $result = $this->parser->parse($crlf);

        $this->assertSame('T', $result['meta']['title']);
        $this->assertSame(['a'], $result['meta']['tags']);
    }
}
