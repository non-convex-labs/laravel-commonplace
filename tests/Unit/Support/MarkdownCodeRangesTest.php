<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Tests\Unit\Support;

use NonConvexLabs\Commonplace\Support\MarkdownCodeRanges;
use PHPUnit\Framework\TestCase;

class MarkdownCodeRangesTest extends TestCase
{
    public function test_returns_empty_for_content_without_code(): void
    {
        $this->assertSame([], MarkdownCodeRanges::find('Just prose with [[a wikilink]].'));
    }

    public function test_finds_triple_backtick_fence(): void
    {
        $content = "before\n```\ninside\n```\nafter\n";
        $ranges = MarkdownCodeRanges::find($content);

        $this->assertCount(1, $ranges);
        $this->assertSame(strpos($content, '```'), $ranges[0][0]);
        $this->assertSame(strpos($content, 'after'), $ranges[0][1] + 1);
    }

    public function test_finds_triple_backtick_fence_with_info_string(): void
    {
        $content = "```text\nhello\n```\n";
        $ranges = MarkdownCodeRanges::find($content);

        $this->assertCount(1, $ranges);
        $this->assertSame(0, $ranges[0][0]);
    }

    public function test_finds_tilde_fence(): void
    {
        $content = "~~~\nhello\n~~~\n";
        $ranges = MarkdownCodeRanges::find($content);

        $this->assertCount(1, $ranges);
    }

    public function test_indented_fence_up_to_three_spaces_is_recognized(): void
    {
        $content = "   ```\nhello\n   ```\n";

        $this->assertCount(1, MarkdownCodeRanges::find($content));
    }

    public function test_four_space_indent_is_not_a_fence(): void
    {
        $content = "    ```\nhello\n    ```\n";

        $this->assertSame([], MarkdownCodeRanges::find($content));
    }

    public function test_finds_inline_code_single_backtick(): void
    {
        $content = 'See `inline code` here.';
        $ranges = MarkdownCodeRanges::find($content);

        $this->assertCount(1, $ranges);
        $this->assertSame(strpos($content, '`'), $ranges[0][0]);
        $this->assertSame(strrpos($content, '`') + 1, $ranges[0][1]);
    }

    public function test_finds_inline_code_double_backtick_with_internal_single(): void
    {
        $content = 'A ``has ` inside`` span.';
        $ranges = MarkdownCodeRanges::find($content);

        $this->assertCount(1, $ranges);
        $this->assertSame(2, $ranges[0][0]);
        $this->assertSame(strpos($content, ' span.'), $ranges[0][1]);
    }

    public function test_contains_returns_true_for_offset_inside_range(): void
    {
        $content = "```\n[[link]]\n```\n";
        $ranges = MarkdownCodeRanges::find($content);
        $linkOffset = strpos($content, '[[');

        $this->assertTrue(MarkdownCodeRanges::contains($ranges, $linkOffset));
    }

    public function test_contains_returns_false_for_offset_outside_range(): void
    {
        $content = "```\nfenced\n```\nOutside [[link]].";
        $ranges = MarkdownCodeRanges::find($content);
        $linkOffset = strpos($content, '[[');

        $this->assertFalse(MarkdownCodeRanges::contains($ranges, $linkOffset));
    }

    public function test_unterminated_fence_runs_to_end_of_document(): void
    {
        $content = "before\n```\nno close here";
        $ranges = MarkdownCodeRanges::find($content);

        $this->assertCount(1, $ranges);
        $this->assertSame(strlen($content), $ranges[0][1]);
    }

    public function test_consecutive_fences_yield_two_ranges(): void
    {
        $content = "```\none\n```\nmiddle\n```\ntwo\n```\n";

        $this->assertCount(2, MarkdownCodeRanges::find($content));
    }

    public function test_crlf_line_endings_close_fence(): void
    {
        $content = "```\r\nhello\r\n```\r\nafter\r\n";
        $ranges = MarkdownCodeRanges::find($content);

        $this->assertCount(1, $ranges);
        $this->assertStringContainsString('after', substr($content, $ranges[0][1]));
    }

    public function test_backtick_fence_close_must_be_long_enough(): void
    {
        $content = "````\ntwo `` not a close\n````\n";

        $this->assertCount(1, MarkdownCodeRanges::find($content));
    }

    public function test_tilde_fence_accepts_backticks_in_info_string(): void
    {
        // CommonMark §4.5: only backtick fences forbid backticks in info string.
        // A `~~~ \`foo\`` opener is valid and content between fences must be masked.
        $content = "~~~ `lang`\n[[Inside]]\n~~~\n[[Outside]]\n";
        $ranges = MarkdownCodeRanges::find($content);

        $insideOffset = (int) strpos($content, '[[Inside]]');
        $outsideOffset = (int) strpos($content, '[[Outside]]');

        $this->assertTrue(MarkdownCodeRanges::contains($ranges, $insideOffset));
        $this->assertFalse(MarkdownCodeRanges::contains($ranges, $outsideOffset));
    }

    public function test_wikilink_immediately_after_fence_close_is_not_masked(): void
    {
        $content = "```\nfenced\n```\n[[Adjacent]]";
        $ranges = MarkdownCodeRanges::find($content);

        $this->assertFalse(MarkdownCodeRanges::contains($ranges, (int) strpos($content, '[[')));
    }

    public function test_wikilink_immediately_before_fence_open_is_not_masked(): void
    {
        $content = "[[Adjacent]]\n```\nfenced\n```\n";
        $ranges = MarkdownCodeRanges::find($content);

        $this->assertFalse(MarkdownCodeRanges::contains($ranges, (int) strpos($content, '[[')));
    }

    public function test_fence_with_backtick_in_info_string_does_not_open_at_first_marker(): void
    {
        // CommonMark §4.5: backtick fences can't have backticks in info string.
        // The first line is therefore NOT a fence opener; the literal text on
        // that line should not be masked.
        $content = "``` ` weird\n[[link]]\n";
        $ranges = MarkdownCodeRanges::find($content);
        $linkOffset = (int) strpos($content, '[[');

        $this->assertFalse(
            MarkdownCodeRanges::contains($ranges, $linkOffset),
            'The wikilink on the line after a malformed fence opener should not be masked.',
        );
    }
}
