<?php

namespace Tests\Support\OpenApi;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Shapes App\Support\OpenApi\ControllerResponses has to survive without
 * producing a wrong answer.
 *
 * They are here rather than in a real controller on purpose: the generator
 * reads source text, so the only honest way to test what it does with a
 * `$this->$name()` call or a `static::` status is to have one — and none of
 * these belong in the application.
 *
 * Outside tests/Feature and tests/Unit so neither PHPUnit's testsuite
 * directories nor Pest's `->in('Feature')` binding collect it, the same
 * arrangement as Tests\Support\AiServiceFake.
 */
class ScannerFixtureController
{
    public const CODE = 201;

    /**
     * A public helper must not be followed: it is an endpoint in its own right
     * and its body says nothing about the caller's response.
     */
    public function usesPublicHelper(): JsonResponse
    {
        return $this->publicHelper();
    }

    public function publicHelper(): JsonResponse
    {
        return response()->json(['from_public_helper' => true], 299);
    }

    /**
     * A call to something that is not a method of this class at all.
     */
    public function usesMissingHelper(): JsonResponse
    {
        // Guarded so it is never executed; the scanner only reads the text.
        if (method_exists($this, 'absentHelper')) {
            $this->absentHelper();
        }

        return response()->json(['ok' => true]);
    }

    /**
     * `$this` not followed by `->`.
     */
    public function usesInstanceOf(): JsonResponse
    {
        return response()->json(['self' => $this instanceof self]);
    }

    /**
     * `$this->` followed by a variable rather than a name.
     */
    public function usesDynamicCall(): JsonResponse
    {
        $name = 'privateHelper';

        return response()->json(['dynamic' => $this->$name()]);
    }

    /**
     * A status expression the scanner cannot resolve: it must fall back to 200
     * rather than guess.
     */
    public function usesClassConstantStatus(): JsonResponse
    {
        return response()->json(['a' => 1], static::CODE);
    }

    /**
     * `Response::class` is a `::` fetch that is not an HTTP status constant.
     */
    public function usesClassFetchStatus(): JsonResponse
    {
        return response()->json(['a' => 1], Response::class === '' ? 200 : 203);
    }

    /**
     * A list, not a map: it has no keys to publish.
     */
    public function returnsAList(): JsonResponse
    {
        return response()->json(['first', 'second']);
    }

    public function usesPrivateHelper(): JsonResponse
    {
        return $this->privateHelper();
    }

    private function privateHelper(): JsonResponse
    {
        return response()->json(['from_private_helper' => true], Response::HTTP_ACCEPTED);
    }
}
