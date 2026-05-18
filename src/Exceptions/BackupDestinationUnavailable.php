<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Exceptions;

use LogicException;
use RuntimeException;
use Throwable;

/**
 * Runtime failure pushing a backup bundle to a configured destination —
 * an HTTP response with a failed status, a connection-level error, or
 * the destination rejecting our request payload.
 *
 * Implements [[PublicMessage]]. Message composition mirrors
 * [[EmbeddingProviderUnavailable]]: package-controlled allowlists
 * ([[DESTINATIONS]] and [[REASONS]]) only, no API URLs, response bodies,
 * credentials, or repo names on the wire.
 *
 * The backup destination runs out-of-band via the `BackupVault` job, so
 * a curated message on this surface is largely for operator-log
 * consistency (the MCP envelope never sees it). It still matters for
 * future surfaces that might read backup-destination errors — keeping
 * the discipline uniform avoids special cases.
 *
 * **Do NOT walk `getPrevious()` into agent-visible surfaces.** The
 * chain intentionally carries operator-only detail (response bodies,
 * URLs); the wire read goes through `getMessage()` only.
 */
final class BackupDestinationUnavailable extends RuntimeException implements PublicMessage
{
    /** @var list<string> */
    private const DESTINATIONS = ['github'];

    /** @var list<string> */
    private const REASONS = ['rate_limited', 'unauthorized', 'invalid_request', 'unexpected_payload', 'transport'];

    public function __construct(
        public readonly string $destination,
        public readonly string $reason,
        ?Throwable $previous = null,
    ) {
        if (! in_array($destination, self::DESTINATIONS, true)) {
            throw new LogicException(
                "Unknown backup destination '{$destination}'. Extend BackupDestinationUnavailable::DESTINATIONS."
            );
        }

        if (! in_array($reason, self::REASONS, true)) {
            throw new LogicException(
                "Unknown reason '{$reason}'. Extend BackupDestinationUnavailable::REASONS."
            );
        }

        parent::__construct(self::messageFor($destination, $reason), 0, $previous);
    }

    public static function fromStatus(string $destination, int $status, ?Throwable $previous = null): self
    {
        return new self($destination, self::reasonForStatus($status), $previous);
    }

    private static function reasonForStatus(int $status): string
    {
        return match (true) {
            $status === 401, $status === 403 => 'unauthorized',
            $status === 429 => 'rate_limited',
            $status >= 400 && $status < 500 => 'invalid_request',
            default => 'transport',
        };
    }

    private static function messageFor(string $destination, string $reason): string
    {
        // The reason is already validated against [[REASONS]] before
        // this private helper runs; the default arm is here to satisfy
        // PHPStan's exhaustiveness check (the runtime allowlist is the
        // real guard).
        return match ($reason) {
            'rate_limited' => "Backup destination '{$destination}' is unavailable (rate-limited). Retry with backoff.",
            'unauthorized' => "Backup destination '{$destination}' rejected the request (unauthorized). Check credentials.",
            'invalid_request' => "Backup destination '{$destination}' rejected the request as invalid. Do not retry.",
            'unexpected_payload' => "Backup destination '{$destination}' returned an unexpected payload.",
            'transport' => "Backup destination '{$destination}' is unavailable (transport error). Retry with backoff.",
            default => throw new LogicException("Unknown reason '{$reason}'."),
        };
    }
}
