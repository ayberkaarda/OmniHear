<?php

namespace Tests\Support\OpenApi;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A resource whose `toArray()` does not return an array literal.
 *
 * App\Support\OpenApi\ResourceSchemas reads the literal out of the source, so
 * this is the shape it has to answer "no fields" for rather than guess.
 */
class ScannerFixtureResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $payload = ['built' => 'indirectly'];

        return $payload;
    }
}
