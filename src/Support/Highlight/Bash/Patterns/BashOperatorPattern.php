<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Support\Highlight\Bash\Patterns;

use Tempest\Highlight\IsPattern;
use Tempest\Highlight\Pattern;
use Tempest\Highlight\Tokens\TokenTypeEnum;

final readonly class BashOperatorPattern implements Pattern
{
    use IsPattern;

    public function getPattern(): string
    {
        return '(?<match>\|\||&&|;;|>&|[<>]{1,2}|[|&])';
    }

    public function getTokenType(): TokenTypeEnum
    {
        return TokenTypeEnum::KEYWORD;
    }
}
