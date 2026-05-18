<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Tests\Unit\Exceptions;

use LogicException;
use NonConvexLabs\Commonplace\Exceptions\BackupDestinationUnavailable;
use NonConvexLabs\Commonplace\Exceptions\PublicMessage;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class BackupDestinationUnavailableTest extends TestCase
{
    public function test_implements_public_message_marker(): void
    {
        $this->assertInstanceOf(PublicMessage::class, new BackupDestinationUnavailable('github', 'transport'));
    }

    public function test_extends_runtime_exception(): void
    {
        $this->assertInstanceOf(RuntimeException::class, new BackupDestinationUnavailable('github', 'transport'));
    }

    public function test_messages_are_static_per_reason(): void
    {
        $this->assertSame(
            "Backup destination 'github' is unavailable (rate-limited). Retry with backoff.",
            (new BackupDestinationUnavailable('github', 'rate_limited'))->getMessage(),
        );
        $this->assertSame(
            "Backup destination 'github' rejected the request (unauthorized). Check credentials.",
            (new BackupDestinationUnavailable('github', 'unauthorized'))->getMessage(),
        );
        $this->assertSame(
            "Backup destination 'github' rejected the request as invalid. Do not retry.",
            (new BackupDestinationUnavailable('github', 'invalid_request'))->getMessage(),
        );
        $this->assertSame(
            "Backup destination 'github' returned an unexpected payload.",
            (new BackupDestinationUnavailable('github', 'unexpected_payload'))->getMessage(),
        );
        $this->assertSame(
            "Backup destination 'github' is unavailable (transport error). Retry with backoff.",
            (new BackupDestinationUnavailable('github', 'transport'))->getMessage(),
        );
    }

    public function test_unknown_destination_is_a_programmer_error(): void
    {
        $this->expectException(LogicException::class);

        new BackupDestinationUnavailable('s3', 'transport');
    }

    public function test_unknown_reason_is_a_programmer_error(): void
    {
        $this->expectException(LogicException::class);

        new BackupDestinationUnavailable('github', 'unknown_reason');
    }

    public function test_from_status_taxonomy_matches_embedding_provider(): void
    {
        $this->assertSame('rate_limited', BackupDestinationUnavailable::fromStatus('github', 429)->reason);
        $this->assertSame('unauthorized', BackupDestinationUnavailable::fromStatus('github', 401)->reason);
        $this->assertSame('unauthorized', BackupDestinationUnavailable::fromStatus('github', 403)->reason);
        $this->assertSame('invalid_request', BackupDestinationUnavailable::fromStatus('github', 422)->reason);
        $this->assertSame('transport', BackupDestinationUnavailable::fromStatus('github', 502)->reason);
    }
}
