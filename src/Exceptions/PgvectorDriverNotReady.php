<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Exceptions;

use RuntimeException;

/**
 * Bootstrap-state diagnostic for the pgvector vector driver — the
 * extension isn't installed, the embedding column is missing, the
 * connection is on the wrong driver. Implements [[PublicMessage]]
 * because the message is a hand-written remediation hint ("run
 * `php artisan commonplace:doctor`") with no PII, no SQL, no
 * connection metadata — it's agent-actionable as-is.
 */
class PgvectorDriverNotReady extends RuntimeException implements PublicMessage {}
