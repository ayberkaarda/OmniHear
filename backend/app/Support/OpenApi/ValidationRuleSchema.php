<?php

namespace App\Support\OpenApi;

use Illuminate\Validation\Rules\In;
use Illuminate\Validation\Rules\Password;
use Stringable;

/**
 * Laravel validation rules -> JSON Schema.
 *
 * This is the half of the generated OpenAPI document that carries real
 * information: a client has to know what it may send, and the form requests
 * already say so precisely. Everything here is derived from `rules()` — nothing
 * is declared twice, so a rule change moves the contract on the next export.
 *
 * Dotted keys (`settings.app_id`) become nested object properties, which is how
 * Laravel means them.
 *
 * Rules with no JSON Schema equivalent — `current_password`, `exists`,
 * `prohibited`, a raw `regex` on a non-string — are carried into the property's
 * `description` rather than dropped, so the document never silently claims a
 * field is less constrained than it is.
 */
final class ValidationRuleSchema
{
    /**
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    public static function fromRules(array $rules): array
    {
        $normalized = [];

        foreach ($rules as $field => $rule) {
            $normalized[$field] = self::tokens($rule);
        }

        ksort($normalized);

        $schema = ['type' => 'object', 'properties' => []];
        $required = [];

        foreach ($normalized as $field => $tokens) {
            if (str_contains($field, '.')) {
                continue;
            }

            $schema['properties'][$field] = self::property($field, $tokens, $normalized);

            if (self::isRequired($tokens)) {
                $required[] = $field;
            }
        }

        if ($required !== []) {
            $schema['required'] = $required;
        }

        return $schema;
    }

    /**
     * `sometimes` before `required` is Laravel for "required *if present*",
     * which is an optional member in JSON Schema. Reading only the `required`
     * token would publish every PATCH field as mandatory — the opposite of what
     * the endpoint accepts.
     *
     * @param  list<string>  $tokens
     */
    private static function isRequired(array $tokens): bool
    {
        return in_array('required', $tokens, true) && ! in_array('sometimes', $tokens, true);
    }

    /**
     * @param  list<string>  $tokens
     * @param  array<string, list<string>>  $all
     * @return array<string, mixed>
     */
    private static function property(string $field, array $tokens, array $all): array
    {
        $property = self::scalar($tokens);

        $children = [];

        foreach ($all as $candidate => $childTokens) {
            if (! str_starts_with($candidate, $field.'.')) {
                continue;
            }

            $child = substr($candidate, strlen($field) + 1);

            if (str_contains($child, '.')) {
                continue;
            }

            $children[$child] = self::property($candidate, $childTokens, $all);
        }

        if ($children !== []) {
            $property['type'] = 'object';
            $property['properties'] = $children;

            $childRequired = array_keys(array_filter(
                $children,
                fn (array $schema, string $name): bool => self::isRequired($all[$field.'.'.$name]),
                ARRAY_FILTER_USE_BOTH,
            ));

            if ($childRequired !== []) {
                $property['required'] = array_values($childRequired);
            }
        }

        $notes = self::notes($tokens);

        if ($notes !== []) {
            $property['description'] = implode(' ', $notes);
        }

        return $property;
    }

    /**
     * @param  list<string>  $tokens
     * @return array<string, mixed>
     */
    private static function scalar(array $tokens): array
    {
        $schema = [];

        foreach ($tokens as $token) {
            [$name, $argument] = array_pad(explode(':', $token, 2), 2, null);

            match ($name) {
                'string' => $schema['type'] = 'string',
                'integer', 'int' => $schema['type'] = 'integer',
                'numeric' => $schema['type'] = 'number',
                'boolean' => $schema['type'] = 'boolean',
                'array' => $schema['type'] = 'array',
                'email' => $schema['format'] = 'email',
                'uuid' => $schema['format'] = 'uuid',
                'date' => $schema['format'] = 'date-time',
                'nullable' => $schema['nullable'] = true,
                'in' => $schema['enum'] = self::enumValues($argument),
                'max' => self::bound($schema, 'max', $argument),
                'min' => self::bound($schema, 'min', $argument),
                'digits' => $schema['pattern'] = '^[0-9]{'.$argument.'}$',
                'regex' => $schema['pattern'] = self::pattern($argument),
                default => null,
            };
        }

        // `array` in Laravel means "a JSON object or list"; almost every use in
        // this application is a keyed object (settings, credentials,
        // preferences), so the document says object and leaves the members to
        // the dotted child rules above.
        if (($schema['type'] ?? null) === 'array') {
            $schema['type'] = 'object';
        }

        return $schema === [] ? ['type' => 'string'] : $schema;
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    private static function bound(array &$schema, string $edge, ?string $argument): void
    {
        if ($argument === null || ! is_numeric($argument)) {
            return;
        }

        $numeric = ($schema['type'] ?? 'string') !== 'string';
        $key = $numeric
            ? ($edge === 'max' ? 'maximum' : 'minimum')
            : ($edge === 'max' ? 'maxLength' : 'minLength');

        $schema[$key] = (int) $argument;
    }

    /**
     * @return list<string>
     */
    private static function enumValues(?string $argument): array
    {
        if ($argument === null || $argument === '') {
            return [];
        }

        return array_values(array_map(
            fn (string $value): string => trim($value, '"\''),
            explode(',', $argument),
        ));
    }

    /**
     * The delimiters and modifiers of a PCRE pattern are stripped: JSON Schema
     * `pattern` is an ECMA-262 regular expression body, not a PHP one.
     */
    private static function pattern(?string $argument): string
    {
        if ($argument === null || strlen($argument) < 2) {
            return '';
        }

        $delimiter = $argument[0];
        $end = strrpos($argument, $delimiter);

        return $end === 0 ? $argument : substr($argument, 1, $end - 1);
    }

    /**
     * Constraints with no JSON Schema equivalent, kept as prose.
     *
     * @param  list<string>  $tokens
     * @return list<string>
     */
    private static function notes(array $tokens): array
    {
        $notes = [];

        foreach ($tokens as $token) {
            $name = explode(':', $token, 2)[0];

            $note = match ($name) {
                'confirmed' => 'Must be repeated in a `<field>_confirmation` member.',
                'current_password' => 'Must equal the authenticated user current password.',
                'prohibited' => 'Must not be sent.',
                'unique' => 'Must not already be taken.',
                'sometimes' => 'Optional; omit to leave unchanged.',
                'different' => 'Must differ from the field named in the rule.',
                'required_with' => 'Required when its parent object is sent.',
                'password' => 'Subject to the application password policy (min 12, uncompromised in production).',
                default => null,
            };

            if ($note !== null && ! in_array($note, $notes, true)) {
                $notes[] = $note;
            }
        }

        return $notes;
    }

    /**
     * Flatten one rule declaration into comparable string tokens.
     *
     * Rule objects are rendered through their own string form — `Rule::in()`
     * produces `in:"a","b"` — so an enum declared as an object and one declared
     * as a string reach this class identically.
     *
     * @return list<string>
     */
    private static function tokens(mixed $rule): array
    {
        if (is_string($rule)) {
            return array_values(array_filter(explode('|', $rule)));
        }

        if (! is_array($rule)) {
            $rule = [$rule];
        }

        $tokens = [];

        foreach ($rule as $entry) {
            if (is_string($entry)) {
                $tokens = array_merge($tokens, array_values(array_filter(explode('|', $entry))));

                continue;
            }

            if ($entry instanceof In || $entry instanceof Stringable || is_object($entry) && method_exists($entry, '__toString')) {
                $tokens[] = (string) $entry;

                continue;
            }

            if ($entry instanceof Password) {
                $tokens[] = 'password';

                continue;
            }

            if (is_object($entry)) {
                $tokens[] = class_basename($entry);
            }
        }

        return array_map(
            fn (string $token): string => str_starts_with($token, 'Illuminate\\') ? class_basename($token) : $token,
            $tokens,
        );
    }
}
