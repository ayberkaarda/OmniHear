<?php

namespace App\Support\OpenApi;

use Illuminate\Http\Response;
use ReflectionClass;

/**
 * What a controller action answers with, read out of its own source.
 *
 * Laravel keeps no declaration of this anywhere: the status code and the body
 * shape exist only as the arguments of a `response()->json(...)` call, and no
 * amount of reflection reaches them. Calling the action instead is not an
 * option — that needs a request, a tenant and a database.
 *
 * So the method body is tokenized and read:
 *
 *   - `noContent()`                  -> 204, no body
 *   - `json(null, 204)`              -> 204, no body
 *   - `json([...])`                  -> 200, with the literal's top-level keys
 *   - `json([...], 201)`             -> 201
 *   - `json([...], Response::HTTP_*) -> the constant's value
 *
 * Private helpers on the same controller are followed one level deep, because
 * the shared `single()` / `serialize()` helpers in this codebase are where
 * several actions actually build their response.
 *
 * # Its limits, stated rather than hidden
 *
 * A key whose value constructs an `XResource` is linked to that component. A
 * key whose value is built some other way — a helper on another class, an
 * inline `map()` over a paginator — is published as an unconstrained member:
 * the *name* is right, the shape is not stated. An action this scanner cannot
 * read at all produces a response with no schema and a description saying so,
 * which is the honest answer and is also visible enough to be fixed.
 */
final class ControllerResponses
{
    /**
     * @return array<int, array{keys: array<string, string|null>, scanned: bool}>
     */
    public static function for(string $controller, string $action): array
    {
        $reflection = new ReflectionClass($controller);

        if (! $reflection->hasMethod($action)) {
            return [];
        }

        $method = $reflection->getMethod($action);
        $own = MethodSource::of($method);

        if ($own === null) {
            return [];
        }

        $ownTokens = PhpArrayKeys::tokenize($own);

        return self::scan(
            self::withHelpers($reflection, $ownTokens),
            self::literalStatuses($ownTokens),
        );
    }

    /**
     * Every 2xx integer literal in the action's *own* body.
     *
     * The shared `single()` helper in IntegrationController takes its status as
     * a parameter, so the `json()` call itself only says `$status` — the 201 of
     * a create lives at the call site. Without this, every create in that
     * controller would be documented as a 200.
     *
     * Scoped to the action's own tokens and to the 2xx range so that a page
     * size or a retry count cannot be mistaken for a status.
     *
     * @param  array<int, array{0: int, 1: string, 2: int}|string>  $tokens
     * @return list<int>
     */
    private static function literalStatuses(array $tokens): array
    {
        $statuses = [];

        foreach ($tokens as $token) {
            if (! is_array($token) || $token[0] !== T_LNUMBER) {
                continue;
            }

            $value = (int) $token[1];

            if ($value >= 200 && $value <= 299 && ! in_array($value, $statuses, true)) {
                $statuses[] = $value;
            }
        }

        return $statuses;
    }

    /**
     * The action's own tokens, followed by those of every private helper it
     * calls on `$this`.
     *
     * @param  ReflectionClass<object>  $reflection
     * @return array<int, array{0: int, 1: string, 2: int}|string>
     */
    private static function withHelpers(ReflectionClass $reflection, array $tokens): array
    {
        foreach (self::calledHelpers($tokens) as $helper) {
            if (! $reflection->hasMethod($helper)) {
                continue;
            }

            $helperMethod = $reflection->getMethod($helper);

            if ($helperMethod->isPublic()) {
                continue;
            }

            $helperSource = MethodSource::of($helperMethod);

            if ($helperSource !== null) {
                $tokens = array_merge($tokens, PhpArrayKeys::tokenize($helperSource));
            }
        }

        return $tokens;
    }

    /**
     * Names appearing as `$this->name(`.
     *
     * @param  array<int, array{0: int, 1: string, 2: int}|string>  $tokens
     * @return list<string>
     */
    private static function calledHelpers(array $tokens): array
    {
        $names = [];
        $count = count($tokens);

        for ($i = 0; $i < $count - 2; $i++) {
            $token = $tokens[$i];

            if (! is_array($token) || $token[0] !== T_VARIABLE || $token[1] !== '$this') {
                continue;
            }

            $arrow = PhpArrayKeys::significant($tokens, $i + 1);

            if ($arrow === null || ! is_array($tokens[$arrow]) || $tokens[$arrow][0] !== T_OBJECT_OPERATOR) {
                continue;
            }

            $name = PhpArrayKeys::significant($tokens, $arrow + 1);

            if ($name === null || ! is_array($tokens[$name]) || $tokens[$name][0] !== T_STRING) {
                continue;
            }

            $paren = PhpArrayKeys::significant($tokens, $name + 1);

            if ($paren !== null && $tokens[$paren] === '(' && ! in_array($tokens[$name][1], $names, true)) {
                $names[] = $tokens[$name][1];
            }
        }

        return $names;
    }

    /**
     * @param  array<int, array{0: int, 1: string, 2: int}|string>  $tokens
     * @param  list<int>  $fallbackStatuses
     * @return array<int, array{keys: array<string, string|null>, scanned: bool}>
     */
    private static function scan(array $tokens, array $fallbackStatuses = []): array
    {
        $responses = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (! is_array($token) || $token[0] !== T_STRING) {
                continue;
            }

            $open = PhpArrayKeys::significant($tokens, $i + 1);

            if ($open === null || $tokens[$open] !== '(') {
                continue;
            }

            if ($token[1] === 'noContent') {
                $responses[204] = ['keys' => [], 'scanned' => true];

                continue;
            }

            if ($token[1] !== 'json') {
                continue;
            }

            $first = PhpArrayKeys::significant($tokens, $open + 1) ?? $open;

            $keys = [];
            $after = $first;

            if ($tokens[$first] === '[') {
                $spans = PhpArrayKeys::at($tokens, $first);

                foreach ($spans as $key => $span) {
                    $keys[$key] = self::resourceIn($tokens, $span['from'], $span['to']);
                }

                $after = PhpArrayKeys::closingOf($tokens, $first);
            }

            $status = self::statusAfter($tokens, $after);

            foreach ($status === null ? ($fallbackStatuses ?: [200]) : [$status] as $code) {
                $responses[$code] = ['keys' => $keys, 'scanned' => true];
            }
        }

        ksort($responses);

        return $responses;
    }

    /**
     * The literal or `Response::HTTP_*` constant that follows the body
     * argument, or 200.
     *
     * @param  array<int, array{0: int, 1: string, 2: int}|string>  $tokens
     */
    private static function statusAfter(array $tokens, int $from): ?int
    {
        $comma = PhpArrayKeys::significant($tokens, $from + 1);

        if ($comma === null || $tokens[$comma] !== ',') {
            return 200;
        }

        $value = PhpArrayKeys::significant($tokens, $comma + 1) ?? $comma;

        if (is_array($tokens[$value]) && $tokens[$value][0] === T_LNUMBER) {
            return (int) $tokens[$value][1];
        }

        // A parameterised status: the value lives at the call site, so the
        // caller resolves it from the action's own literals.
        if (is_array($tokens[$value]) && $tokens[$value][0] === T_VARIABLE) {
            return null;
        }

        if (! is_array($tokens[$value]) || $tokens[$value][0] !== T_STRING || $tokens[$value][1] !== 'Response') {
            return 200;
        }

        $constant = PhpArrayKeys::significant($tokens, $value + 2) ?? $value;
        $token = $tokens[$constant];

        // defined() is the only guard needed: anything that is not a real
        // Response::HTTP_* constant - a ::class fetch, a dynamic name - lands
        // on a name nothing has defined and falls back to 200.
        $name = Response::class.'::'.(is_array($token) ? $token[1] : '');

        return defined($name) ? (int) constant($name) : 200;
    }

    /**
     * The basename of the API Resource constructed inside a value span, if any.
     *
     * @param  array<int, array{0: int, 1: string, 2: int}|string>  $tokens
     */
    private static function resourceIn(array $tokens, int $from, int $to): ?string
    {
        for ($i = $from; $i < $to; $i++) {
            $token = $tokens[$i];

            if (is_array($token) && $token[0] === T_STRING && str_ends_with($token[1], 'Resource')) {
                return $token[1];
            }
        }

        return null;
    }
}
