<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Support;

/**
 * Parse a comma-separated middleware env value into the array form
 * Laravel's router expects.
 *
 * A flat `explode(',', …)` works for simple lists like `web,auth` but
 * breaks parameterized middleware like `throttle:30,1` — the parameter
 * comma collides with the middleware separator and you end up with
 * `['web', 'throttle:30', '1']`. Laravel then tries to construct `'1'`
 * as a middleware class and 500s.
 *
 * The rule applied here: a comma starts the next middleware only if
 * what follows looks like another middleware identifier (a letter,
 * underscore, or backslash, optionally preceded by whitespace).
 * Commas followed by digits stay attached as parameter values. This
 * covers the documented cases:
 *
 *   web,auth                       → ['web', 'auth']
 *   web,throttle:30,1              → ['web', 'throttle:30,1']
 *   web,throttle:30,1,auth:sanctum → ['web', 'throttle:30,1', 'auth:sanctum']
 *
 * Edge case worth knowing: Laravel's `throttle` middleware accepts a
 * third *prefix* argument (`throttle:60,1,api`). Because `api` is a
 * legal middleware identifier too, this parser cannot disambiguate
 * the two interpretations and will split — yielding two middlewares.
 * If you need a prefix arg, set the stack via the
 * `commonplace.routes.*.middleware` config array directly rather than
 * through the env string.
 */
final class MiddlewareList
{
    /**
     * Parse the env-style string into an ordered list of middleware
     * identifiers, preserving parameter commas.
     *
     * @return list<string>
     */
    public static function parse(string $value): array
    {
        $value = trim($value);

        if ($value === '') {
            return [];
        }

        // Split on commas only when followed by something that looks
        // like a middleware identifier start (letter, underscore, or
        // namespace backslash). Whitespace after the comma is allowed
        // and trimmed in the segment pass below.
        $segments = preg_split('/,(?=\s*[A-Za-z_\\\\])/', $value);

        if ($segments === false) {
            return [];
        }

        $out = [];
        foreach ($segments as $segment) {
            // Strip stray leading / trailing commas left over from
            // doubled or trailing separators that the splitter could
            // not consume (e.g. `web,,auth` or `web,auth,`). The
            // splitter only fires on `, + identifier-start`, so a
            // dangling comma sticks to its segment.
            $segment = trim($segment, " \t\n\r\0\x0B,");

            if ($segment === '') {
                continue;
            }

            $out[] = $segment;
        }

        return $out;
    }
}
