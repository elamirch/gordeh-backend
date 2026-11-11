<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Chat text is plain text: the frontend escapes it, and we additionally drop anything that
 * is shaped like an HTML tag/comment so markup never reaches the agent console or the DB.
 * Only real tag shapes (`<` followed by a letter, `/` or `!`) are removed, so "2 < 3" and
 * Persian text in angle brackets survive untouched.
 */
class PlainText
{
    private const MAX_CLEANED_BYTES = 20000;

    public static function clean(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        // `[^<>]*` (not `[^>]*`) keeps this linear on hostile input like "<a<a<a<a…": a failed
        // match can never rescan past the next `<`.
        $value = preg_replace('/<\/?[a-z!][^<>]*>/i', '', $value) ?? $value;

        return trim($value);
    }

    /** Cleans the given string inputs in place before validation; non-strings are left for the validator to reject. */
    public static function sanitizeRequest(Request $request, array $keys): void
    {
        foreach ($keys as $key) {
            $value = $request->input($key);

            // Anything this long fails the endpoints' max-length rules anyway; don't spend CPU on it.
            if (is_string($value) && strlen($value) <= self::MAX_CLEANED_BYTES) {
                $request->merge([$key => self::clean($value)]);
            }
        }
    }
}
