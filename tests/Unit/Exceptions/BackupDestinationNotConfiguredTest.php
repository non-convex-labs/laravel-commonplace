<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Tests\Unit\Exceptions;

use LogicException;
use NonConvexLabs\Commonplace\Exceptions\BackupDestinationNotConfigured;
use NonConvexLabs\Commonplace\Exceptions\PublicMessage;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class BackupDestinationNotConfiguredTest extends TestCase
{
    public function test_implements_public_message_marker(): void
    {
        $this->assertInstanceOf(PublicMessage::class, new BackupDestinationNotConfigured('github'));
    }

    public function test_extends_runtime_exception(): void
    {
        $this->assertInstanceOf(RuntimeException::class, new BackupDestinationNotConfigured('github'));
    }

    public function test_message_is_static(): void
    {
        $this->assertSame(
            "Backup destination 'github' is not configured.",
            (new BackupDestinationNotConfigured('github'))->getMessage(),
        );
    }

    public function test_unknown_destination_is_a_programmer_error(): void
    {
        $this->expectException(LogicException::class);

        new BackupDestinationNotConfigured('s3');
    }

    public function test_no_config_keys_or_remediation_hints_in_message(): void
    {
        $message = (new BackupDestinationNotConfigured('github'))->getMessage();

        $this->assertStringNotContainsString('commonplace.backup', $message);
        $this->assertStringNotContainsString('repo', $message);
        $this->assertStringNotContainsString('token', $message);
    }
}
