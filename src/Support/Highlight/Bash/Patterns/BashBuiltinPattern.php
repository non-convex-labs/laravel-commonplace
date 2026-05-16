<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Support\Highlight\Bash\Patterns;

use Tempest\Highlight\IsPattern;
use Tempest\Highlight\Pattern;
use Tempest\Highlight\Tokens\TokenTypeEnum;

final readonly class BashBuiltinPattern implements Pattern
{
    use IsPattern;

    public function getPattern(): string
    {
        $builtins = implode('|', [
            'echo', 'printf', 'read', 'cd', 'pwd', 'pushd', 'popd',
            'dirs', 'let', 'test', 'command', 'type', 'hash',
            'mkdir', 'rmdir', 'rm', 'cp', 'mv', 'cat', 'ls',
            'chmod', 'chown', 'grep', 'sed', 'awk', 'find',
            'curl', 'wget', 'tar', 'gzip', 'gunzip',
            'head', 'tail', 'sort', 'uniq', 'wc', 'cut', 'tr',
            'tee', 'xargs', 'touch', 'date', 'sleep',
        ]);

        return "(?:^|(?<=[\s;|&]))(?<match>{$builtins})(?=\s|$|;|\|)";
    }

    public function getTokenType(): TokenTypeEnum
    {
        return TokenTypeEnum::TYPE;
    }
}
