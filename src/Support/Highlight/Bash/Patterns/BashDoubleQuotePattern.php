<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Support\Highlight\Bash\Patterns;

use Tempest\Highlight\IsPattern;
use Tempest\Highlight\Pattern;
use Tempest\Highlight\Tokens\TokenTypeEnum;

final readonly class BashDoubleQuotePattern implements Pattern
{
    use IsPattern;

    public function getPattern(): string
    {
        return '(?<match>"(?:[^"\\\\]|\\\\.)*")';
    }

    public function getTokenType(): TokenTypeEnum
    {
        return TokenTypeEnum::VALUE;
    }
}
