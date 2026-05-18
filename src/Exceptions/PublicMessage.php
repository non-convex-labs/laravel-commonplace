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
 * Implement on your own exception classes when the message is
 * agent-actionable, hand-curated, and known not to embed PII —
 * absolute filesystem paths, internal URLs, bearer tokens, env values,
 * cache keys, model class names, connection metadata, SQL fragments,
 * or PDO's `DETAIL:` row data.
 *
 * **Public API surface (`@api`).** Package consumers extending the
 * MCP tool surface — custom embedding providers, custom backup
 * destinations, custom MCP tools — can implement this on their own
 * exception classes to opt those messages into the agent-visible
 * envelope.
 *
 * Database-layer exceptions (`QueryException`, `PDOException`,
 * `LostConnectionException`) are redacted by an earlier, more
 * specific branch in `publicMessageFor()` regardless of whether they
 * implement this interface — the SQLSTATE-preserving collapse
 * supersedes pass-through for that class hierarchy.
 */
interface PublicMessage {}
