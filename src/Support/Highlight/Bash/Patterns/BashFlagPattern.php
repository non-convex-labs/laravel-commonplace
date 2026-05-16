<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Support\Highlight\Bash\Patterns;

use Tempest\Highlight\IsPattern;
use Tempest\Highlight\Pattern;
use Tempest\Highlight\Tokens\TokenTypeEnum;

final readonly class BashFlagPattern implements Pattern
{
    use IsPattern;

    public function getPattern(): string
    {
        return '(?<=\s)(?<match>--?[a-zA-Z][a-zA-Z0-9-]*)';
    }

    public function getTokenType(): TokenTypeEnum
    {
        return TokenTypeEnum::PROPERTY;
    }
}
