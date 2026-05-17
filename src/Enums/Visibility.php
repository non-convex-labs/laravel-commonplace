<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Enums;

enum Visibility: string
{
    /** Default. Readable by the owner and any user granted access via a Share row. */
    case Private = 'private';

    /** Readable by anyone — including unauthenticated visitors when the public-read route group is enabled. */
    case Public = 'public';

    /**
     * Canonical token list. Single source of truth for any MCP schema
     * description, Validator `in:` rule, or doctor check that needs to
     * enumerate the accepted values.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }

    /**
     * Canonical phrasing fragment for human-facing schema / validation
     * messages — e.g. `"Visibility: private or public"`. Tools prefix
     * this with their own context (`"Filter by visibility: "`,
     * `"New visibility: "`, …).
     */
    public static function describe(): string
    {
        return implode(' or ', self::values());
    }
}
