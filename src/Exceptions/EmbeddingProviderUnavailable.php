<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Exceptions;

use LogicException;
use RuntimeException;
use Throwable;

/**
 * Runtime failure from a configured embedding provider — an HTTP response
 * with a failed status, a connection-level error, or a malformed payload.
 *
 * Implements [[PublicMessage]] because the message is composed entirely
 * from two package-controlled allowlists ([[PROVIDERS]] and [[REASONS]])
 * plus a static remediation hint per reason. The MUST NOT clauses in
 * [[PublicMessage]] are honoured by construction: there's no path for
 * `$response->body()`, request URLs, API keys, or any caller-supplied
 * input to land in `getMessage()`.
 *
 * `previous:` is preserved so operators see the underlying
 * `ConnectionException` / driver-internal exception via `report()`. The
 * envelope sanitiser never walks `$previous`, so anything downstream of
 * here stays operator-side.
 *
 * **Do NOT walk `getPrevious()` into agent-visible surfaces.** The
 * chain intentionally carries operator-only detail (HTTP status
 * codes, request IDs, AWS-SDK error trails, signed URLs). The wire
 * read goes through `getMessage()` only, which is the curated string.
 *
 * ## Why two classes (this vs. [[EmbeddingProviderNotConfigured]])
 *
 * The classes split on retry semantics, not message shape. A caller that
 * wants to discriminate ("transient → retry; configuration → stop and
 * page the operator") can `catch` on either type. The wire side gets a
 * different message either way.
 */
final class EmbeddingProviderUnavailable extends RuntimeException implements PublicMessage
{
    /** @var list<string> */
    private const PROVIDERS = ['openai', 'voyage', 'cohere', 'bedrock'];

    /** @var list<string> */
    private const REASONS = ['rate_limited', 'unauthorized', 'invalid_request', 'unexpected_payload', 'transport'];

    public function __construct(
        public readonly string $provider,
        public readonly string $reason,
        ?Throwable $previous = null,
    ) {
        if (! in_array($provider, self::PROVIDERS, true)) {
            throw new LogicException(
                "Unknown embedding provider '{$provider}'. Extend EmbeddingProviderUnavailable::PROVIDERS."
            );
        }

        if (! in_array($reason, self::REASONS, true)) {
            throw new LogicException(
                "Unknown reason '{$reason}'. Extend EmbeddingProviderUnavailable::REASONS."
            );
        }

        parent::__construct(self::messageFor($provider, $reason), 0, $previous);
    }

    public static function fromStatus(string $provider, int $status, ?Throwable $previous = null): self
    {
        return new self($provider, self::reasonForStatus($status), $previous);
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

    private static function messageFor(string $provider, string $reason): string
    {
        // The reason is already validated against [[REASONS]] before
        // this private helper runs; the default arm is here to satisfy
        // PHPStan's exhaustiveness check (the runtime allowlist is the
        // real guard).
        return match ($reason) {
            'rate_limited' => "Embedding provider '{$provider}' is unavailable (rate-limited). Retry with backoff.",
            'unauthorized' => "Embedding provider '{$provider}' rejected the request (unauthorized). Check the configured API key.",
            'invalid_request' => "Embedding provider '{$provider}' rejected the request as invalid. Do not retry.",
            'unexpected_payload' => "Embedding provider '{$provider}' returned an unexpected payload.",
            'transport' => "Embedding provider '{$provider}' is unavailable (transport error). Retry with backoff.",
            default => throw new LogicException("Unknown reason '{$reason}'."),
        };
    }
}
