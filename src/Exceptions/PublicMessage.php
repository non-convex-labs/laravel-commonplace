<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Exceptions;

/**
 * Opt-in marker for exceptions whose `getMessage()` is safe to surface
 * over the wire — agent client, MCP envelope, public HTTP error body.
 *
 * The MCP server's `CommonplaceMcpServer::publicMessageFor()` (the
 * sanitiser layer between an unhandled Throwable from a tool handler
 * and the JSON-RPC `result.isError` envelope) fail-closes on any
 * Throwable that does NOT implement this interface, replacing the
 * message with a fixed generic string. Operators still see the full
 * exception via `report()`.
 *
 * ## Contract for implementers
 *
 * The implementer's `getMessage()` MUST be a hand-curated, static
 * string (optionally interpolating only package- or framework-owned
 * values that are known not to embed PII — e.g. the pgvector driver
 * name, an enum case, a static SQLSTATE family). The message MUST NOT
 * concatenate or interpolate:
 *
 * - **Caller-supplied input** — request bodies, query parameters,
 *   request paths, header values, user-controlled config keys.
 * - **Model attributes** — Eloquent `$model->id`, `$model->name`, or
 *   any column that originated as user input.
 * - **Response bodies from third-party APIs** — `$response->body()`
 *   from an HTTP client call can echo back the request payload
 *   (provider abuse-detection logs, validation errors that quote the
 *   offending field), which may include the user's note content sent
 *   for embedding.
 * - **Connection metadata** — host, port, database, connection name
 *   (`getName()` vs the safer `getDriverName()`), credentials.
 * - **Filesystem paths** — absolute or even relative paths that
 *   reveal the host application's layout.
 * - **Internal URLs or signed query strings** — bearer tokens,
 *   pre-signed S3 URLs, request signatures.
 *
 * Counter-example (DON'T do this):
 *
 *     class TenantQuotaExceeded extends RuntimeException implements PublicMessage
 *     {
 *         public function __construct(string $tenant)
 *         {
 *             parent::__construct("tenant {$tenant} quota exceeded"); // ← LEAK
 *         }
 *     }
 *
 * The `{$tenant}` argument is caller-controlled; if it ever carries
 * email, an internal identifier, or a path-like value, it leaks
 * through a *marked* class. Either drop the interpolation
 * (`"Tenant quota exceeded."`) or do not mark the class.
 *
 * ## Resolution order
 *
 * Database-layer exceptions (`QueryException`, `PDOException`,
 * `LostConnectionException`) are redacted by an earlier, more
 * specific branch in `publicMessageFor()` regardless of whether they
 * implement this interface — the SQLSTATE-preserving collapse
 * supersedes pass-through for that class hierarchy. Marking a
 * `PDOException` subclass `PublicMessage` does NOT bypass the SQLSTATE
 * collapse.
 *
 * ## Public API surface
 *
 * `@api`. Package consumers extending the MCP tool surface — custom
 * embedding providers, custom backup destinations, custom MCP tools
 * — implement this on their own exception classes to opt those
 * messages into the agent-visible envelope.
 */
interface PublicMessage {}
