<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Support\Highlight\Bash;

use NonConvexLabs\Commonplace\Support\Highlight\Bash\Patterns\BashBuiltinPattern;
use NonConvexLabs\Commonplace\Support\Highlight\Bash\Patterns\BashCommentPattern;
use NonConvexLabs\Commonplace\Support\Highlight\Bash\Patterns\BashDoubleQuotePattern;
use NonConvexLabs\Commonplace\Support\Highlight\Bash\Patterns\BashFlagPattern;
use NonConvexLabs\Commonplace\Support\Highlight\Bash\Patterns\BashKeywordPattern;
use NonConvexLabs\Commonplace\Support\Highlight\Bash\Patterns\BashOperatorPattern;
use NonConvexLabs\Commonplace\Support\Highlight\Bash\Patterns\BashSingleQuotePattern;
use NonConvexLabs\Commonplace\Support\Highlight\Bash\Patterns\BashVariablePattern;
use Override;
use Tempest\Highlight\Languages\Base\BaseLanguage;

class BashLanguage extends BaseLanguage
{
    public function getName(): string
    {
        return 'bash';
    }

    #[Override]
    public function getAliases(): array
    {
        return ['sh', 'shell', 'zsh'];
    }

    #[Override]
    public function getPatterns(): array
    {
        return [
            ...parent::getPatterns(),
            new BashCommentPattern,
            new BashKeywordPattern,
            new BashBuiltinPattern,
            new BashVariablePattern,
            new BashDoubleQuotePattern,
            new BashSingleQuotePattern,
            new BashOperatorPattern,
            new BashFlagPattern,
        ];
    }
}
