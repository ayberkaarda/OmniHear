<?php

namespace App\Support\OpenApi;

use Illuminate\Http\Resources\Json\JsonResource;
use ReflectionClass;
use ReflectionMethod;

/**
 * Component schemas for every App\Http\Resources\Api\V1 resource.
 *
 * The field list is read out of each `toArray()` rather than declared here, so
 * a field added to a resource appears in the contract on the next export and a
 * field removed from one disappears — which is what makes the drift test
 * meaningful.
 *
 * # What this cannot express, and why
 *
 * A resource only has types at run time, and running one needs a model
 * instance: `UserResource::toArray()` reads `$this->email_verified_at?->
 * toIso8601String()`, which is a string or null depending on a row. Inferring
 * "ends with `_at`, therefore date-time" would be a guess, and a contract
 * document that guesses is worse than one that says nothing — the reader cannot
 * tell the guesses from the facts. So each property is published as an
 * unconstrained schema: the *field set* is authoritative, the types are not
 * stated. The prose contracts in docs/contracts/ carry the types.
 */
final class ResourceSchemas
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        $schemas = [];

        foreach (self::classes() as $class) {
            $fields = self::fields($class);
            $properties = [];

            foreach ($fields as $field) {
                $properties[$field] = (object) [];
            }

            $schemas[class_basename($class)] = [
                'type' => 'object',
                'description' => 'Generated from '.$class.'::toArray(). '
                    .'Field names are authoritative; member types are not expressed '
                    .'(see the class docblock on App\Support\OpenApi\ResourceSchemas).',
                'properties' => $properties,
                'required' => $fields,
            ];
        }

        ksort($schemas);

        return $schemas;
    }

    /**
     * @return list<class-string<JsonResource>>
     */
    public static function classes(): array
    {
        $directory = app_path('Http/Resources/Api/V1');
        $files = glob($directory.'/*.php') ?: [];
        sort($files);

        $classes = array_map(
            fn (string $file): string => 'App\\Http\\Resources\\Api\\V1\\'.basename($file, '.php'),
            $files,
        );

        return array_values(array_filter(
            $classes,
            fn (string $class): bool => is_subclass_of($class, JsonResource::class),
        ));
    }

    /**
     * The top-level keys of the array `toArray()` returns.
     *
     * @param  class-string<JsonResource>  $class
     * @return list<string>
     */
    public static function fields(string $class): array
    {
        $reflection = new ReflectionClass($class);

        if (! $reflection->hasMethod('toArray')) {
            return [];
        }

        $method = $reflection->getMethod('toArray');

        return array_keys(self::returnedKeys($method));
    }

    /**
     * @return array<string, array{from: int, to: int}>
     */
    private static function returnedKeys(ReflectionMethod $method): array
    {
        // An internal method has no source; tokenizing the empty string is
        // the same answer as a body with no array literal in it.
        $tokens = PhpArrayKeys::tokenize(MethodSource::of($method) ?? '');

        foreach ($tokens as $index => $token) {
            if (! is_array($token) || $token[0] !== T_RETURN) {
                continue;
            }

            $open = PhpArrayKeys::significant($tokens, $index + 1);

            if ($open !== null && $tokens[$open] === '[') {
                return PhpArrayKeys::at($tokens, $open);
            }
        }

        return [];
    }
}
