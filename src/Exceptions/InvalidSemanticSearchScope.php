<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Exceptions;

use InvalidArgumentException;
use NonConvexLabs\Commonplace\Enums\SemanticSearchScope;

/**
 * The agent passed an unrecognised `scope` to a semantic-search tool.
 *
 * Implements [[PublicMessage]] with a message composed entirely from
 * the [[SemanticSearchScope]] enum (package-controlled). The caller's
 * invalid input is deliberately NOT echoed — the previous
 * `\InvalidArgumentException("Unknown scope '{$rawScope}'. ...")`
 * looked benign (agent input echoed back to agent) but bypassed the
 * MCP envelope chokepoint. Routing this through a typed
 * `PublicMessage` keeps any future non-marker exception fail-closed.
 *
 * Extends `\InvalidArgumentException` for source-compat with callers
 * (and tests) that catch on that family.
 */
final class InvalidSemanticSearchScope extends InvalidArgumentException implements PublicMessage
{
    public function __construct()
    {
        $cases = implode(', ', array_map(
            fn (SemanticSearchScope $scope): string => $scope->value,
            SemanticSearchScope::cases(),
        ));

        parent::__construct("Unknown semantic-search scope. Use one of: {$cases}.");
    }
}
