<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Exceptions;

use LogicException;
use RuntimeException;
use Throwable;

/**
 * Bootstrap-time configuration failure for an embedding provider —
 * missing API key, unknown model (no known native-dimensions entry),
 * model/dimensions mismatch, missing optional SDK dependency.
 *
 * Implements [[PublicMessage]]. The message is composed entirely from
 * the [[PROVIDERS]] allowlist; no config keys, env values, model
 * strings, or remediation hints land on the wire. Operators still see
 * the originating exception detail via `report()` (the agent-visible
 * "not configured" doesn't help the agent anyway — only the operator
 * can fix this state).
 *
 * Distinct from [[EmbeddingProviderUnavailable]] so callers can
 * discriminate on retry semantics: transient (Unavailable, retry) vs.
 * operator-action-required (NotConfigured, do not retry — the next
 * attempt fails the same way until config changes).
 */
final class EmbeddingProviderNotConfigured extends RuntimeException implements PublicMessage
{
    /** @var list<string> */
    private const PROVIDERS = ['openai', 'voyage', 'cohere', 'bedrock'];

    public function __construct(
        public readonly string $provider,
        ?Throwable $previous = null,
    ) {
        if (! in_array($provider, self::PROVIDERS, true)) {
            throw new LogicException(
                "Unknown embedding provider '{$provider}'. Extend EmbeddingProviderNotConfigured::PROVIDERS."
            );
        }

        parent::__construct("Embedding provider '{$provider}' is not configured.", 0, $previous);
    }
}
