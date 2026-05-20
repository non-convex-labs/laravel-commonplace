<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Tests\Unit\Exceptions;

use InvalidArgumentException;
use NonConvexLabs\Commonplace\Enums\SemanticSearchScope;
use NonConvexLabs\Commonplace\Exceptions\InvalidSemanticSearchScope;
use NonConvexLabs\Commonplace\Exceptions\PublicMessage;
use PHPUnit\Framework\TestCase;

class InvalidSemanticSearchScopeTest extends TestCase
{
    public function test_implements_public_message_marker(): void
    {
        $this->assertInstanceOf(PublicMessage::class, new InvalidSemanticSearchScope);
    }

    public function test_extends_invalid_argument_exception(): void
    {
        $this->assertInstanceOf(InvalidArgumentException::class, new InvalidSemanticSearchScope);
    }

    public function test_message_lists_every_enum_case(): void
    {
        $message = (new InvalidSemanticSearchScope)->getMessage();

        foreach (SemanticSearchScope::cases() as $scope) {
            $this->assertStringContainsString($scope->value, $message);
        }
    }

    public function test_message_does_not_echo_caller_input(): void
    {
        // SuggestedLinksTool / SemanticSearchTool previously echoed
        // the raw $rawScope back to the agent. Even though the agent
        // sent it, a sibling InvalidArgumentException with operator
        // data could ride the same catch — narrowing to a typed
        // PublicMessage class with a static body forecloses that.
        $this->assertStringNotContainsString("'", (new InvalidSemanticSearchScope)->getMessage());
    }
}
