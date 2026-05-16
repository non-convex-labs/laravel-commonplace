<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Support\Highlight\Bash\Patterns;

use Tempest\Highlight\IsPattern;
use Tempest\Highlight\Pattern;
use Tempest\Highlight\Tokens\TokenTypeEnum;

final readonly class BashKeywordPattern implements Pattern
{
    use IsPattern;

    public function getPattern(): string
    {
        $keywords = implode('|', [
            'if', 'then', 'else', 'elif', 'fi',
            'for', 'while', 'until', 'do', 'done',
            'case', 'esac', 'in',
            'function', 'return', 'exit',
            'local', 'declare', 'export', 'readonly', 'typeset',
            'source', 'eval', 'exec',
            'break', 'continue', 'shift',
            'trap', 'set', 'unset',
            'true', 'false',
        ]);

        return "\b(?<match>{$keywords})\b";
    }

    public function getTokenType(): TokenTypeEnum
    {
        return TokenTypeEnum::KEYWORD;
    }
}
