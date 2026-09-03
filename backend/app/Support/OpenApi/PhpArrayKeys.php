<?php

namespace App\Support\OpenApi;

/**
 * Reads the top-level string keys out of a PHP array literal, from tokens.
 *
 * Used twice: to learn which fields an API Resource serializes, and which
 * top-level members a controller puts in a `response()->json([...])` body.
 * Neither can be discovered by calling the code — a Resource needs a model
 * instance and a controller needs a request — so the source is read instead.
 *
 * Token-based rather than regular expressions on purpose: a regex over
 * indentation breaks the moment a formatter moves a line, and a nested array
 * would leak its own keys into the result.
 */
final class PhpArrayKeys
{
    /**
     * The keys of the array literal that opens at $offset (which must be the
     * index of its `[` token), together with the token span of each value.
     *
     * @param  array<int, array{0: int, 1: string, 2: int}|string>  $tokens
     * @return array<string, array{from: int, to: int}>
     */
    public static function at(array $tokens, int $offset): array
    {
        $keys = [];
        $depth = 0;
        $count = count($tokens);
        $pendingKey = null;
        $valueFrom = null;

        for ($i = $offset; $i < $count; $i++) {
            $token = $tokens[$i];

            if ($token === '[' || $token === '(') {
                $depth++;

                continue;
            }

            if ($token === ']' || $token === ')') {
                $depth--;

                if ($depth === 0) {
                    if ($pendingKey !== null && $valueFrom !== null) {
                        $keys[$pendingKey] = ['from' => $valueFrom, 'to' => $i];
                    }

                    break;
                }

                continue;
            }

            if ($depth !== 1) {
                continue;
            }

            if ($token === ',') {
                if ($pendingKey !== null && $valueFrom !== null) {
                    $keys[$pendingKey] = ['from' => $valueFrom, 'to' => $i];
                }

                $pendingKey = null;
                $valueFrom = null;

                continue;
            }

            if ($pendingKey !== null) {
                continue;
            }

            if (! is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }

            $next = self::significant($tokens, $i + 1);

            if ($next === null || ! is_array($tokens[$next]) || $tokens[$next][0] !== T_DOUBLE_ARROW) {
                continue;
            }

            $pendingKey = trim($token[1], "'\"");
            $valueFrom = $next + 1;
        }

        return $keys;
    }

    /**
     * Index of the bracket or parenthesis that closes the one at $open.
     *
     * Falls back to $open itself for a stream that never closes it, which
     * cannot happen for the body of a method PHP has already parsed — the
     * fallback is there so a malformed fragment produces a small answer rather
     * than an out-of-range index.
     *
     * @param  array<int, array{0: int, 1: string, 2: int}|string>  $tokens
     */
    public static function closingOf(array $tokens, int $open): int
    {
        $depth = 0;
        $count = count($tokens);

        for ($i = $open; $i < $count; $i++) {
            if ($tokens[$i] === '[' || $tokens[$i] === '(') {
                $depth++;
            } elseif ($tokens[$i] === ']' || $tokens[$i] === ')') {
                $depth--;

                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return $open;
    }

    /**
     * Index of the first token at or after $from that is not whitespace or a
     * comment, or null.
     *
     * @param  array<int, array{0: int, 1: string, 2: int}|string>  $tokens
     */
    public static function significant(array $tokens, int $from): ?int
    {
        $count = count($tokens);

        for ($i = $from; $i < $count; $i++) {
            $token = $tokens[$i];

            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $i;
        }

        return null;
    }

    /**
     * Tokenize a fragment of PHP source that has no opening tag of its own.
     *
     * @return array<int, array{0: int, 1: string, 2: int}|string>
     */
    public static function tokenize(string $source): array
    {
        $tokens = token_get_all('<?php '.$source);

        // Drop the synthetic open tag so offsets refer to the fragment.
        array_shift($tokens);

        return array_values($tokens);
    }
}
