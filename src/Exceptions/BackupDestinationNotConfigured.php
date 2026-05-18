<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Exceptions;

use LogicException;
use RuntimeException;
use Throwable;

/**
 * Bootstrap-time configuration failure for a backup destination —
 * missing repo name or token for the GitHub destination, etc.
 *
 * Implements [[PublicMessage]]. The message is composed entirely from
 * the [[DESTINATIONS]] allowlist; no config keys, repo names, or
 * remediation detail land on the wire.
 */
final class BackupDestinationNotConfigured extends RuntimeException implements PublicMessage
{
    /** @var list<string> */
    private const DESTINATIONS = ['github'];

    public function __construct(
        public readonly string $destination,
        ?Throwable $previous = null,
    ) {
        if (! in_array($destination, self::DESTINATIONS, true)) {
            throw new LogicException(
                "Unknown backup destination '{$destination}'. Extend BackupDestinationNotConfigured::DESTINATIONS."
            );
        }

        parent::__construct("Backup destination '{$destination}' is not configured.", 0, $previous);
    }
}
