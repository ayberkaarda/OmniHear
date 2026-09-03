<?php

use App\Http\Controllers\Api\V1\IntegrationController;
use App\Http\Controllers\Api\V1\Settings\ApiKeyController;
use App\Http\Controllers\Api\V1\Settings\ProfileController;
use App\Http\Resources\Api\V1\UserResource;
use App\Support\Http\ApiErrorCode;
use App\Support\OpenApi\ControllerResponses;
use App\Support\OpenApi\OpenApiContract;
use App\Support\OpenApi\OpenApiDocument;
use App\Support\OpenApi\ResourceSchemas;
use App\Support\OpenApi\ValidationRuleSchema;
use Illuminate\Routing\Route;
use Illuminate\Validation\Rule;

/**
 * `contracts/http-api-v1.json` must never drift from the application
 * (spec section 10).
 *
 * The same arrangement the ai-service has had since F3: the document is
 * generated, not written, so the only failure mode left is forgetting to
 * regenerate it — and that is what these tests turn red.
 *
 * Regenerate with, from the repository root:
 *
 *   docker compose -f infra/docker-compose.dev.yml run --rm backend \
 *     php artisan openapi:export --print > contracts/http-api-v1.json
 */
it('finds the committed contract', function () {
    expect(OpenApiContract::read())->not->toBeNull(
        OpenApiContract::path().' is missing. Run: php artisan openapi:export --print > contracts/http-api-v1.json',
    );
});

it('keeps the committed contract identical to what the application produces', function () {
    expect(OpenApiContract::read())->toBe(
        OpenApiContract::serialize(OpenApiDocument::build()),
        'contracts/http-api-v1.json is stale. Run: php artisan openapi:export --print > contracts/http-api-v1.json',
    );
});

it('exports reproducibly', function () {
    expect(OpenApiContract::serialize(OpenApiDocument::build()))
        ->toBe(OpenApiContract::serialize(OpenApiDocument::build()));
});

it('documents every api route and nothing else', function () {
    $document = OpenApiDocument::build();

    $expected = [];

    foreach (OpenApiDocument::routes() as $route) {
        $expected['/'.ltrim($route->uri(), '/')] = true;
    }

    expect(array_keys($document['paths']))->toEqualCanonicalizing(array_keys($expected));
});

it('documents every verb of every route', function () {
    $document = OpenApiDocument::build();

    foreach (OpenApiDocument::routes() as $route) {
        $path = '/'.ltrim($route->uri(), '/');

        foreach (array_diff($route->methods(), ['HEAD']) as $method) {
            expect($document['paths'][$path])->toHaveKey(strtolower($method));
        }
    }
});

it('gives every operation a unique operationId', function () {
    $ids = [];

    foreach (OpenApiDocument::build()['paths'] as $operations) {
        foreach ($operations as $operation) {
            $ids[] = $operation['operationId'];
        }
    }

    expect($ids)->toBe(array_unique($ids));
});

it('marks every authenticated route as bearer secured and no other', function () {
    $document = OpenApiDocument::build();

    foreach (OpenApiDocument::routes() as $route) {
        $path = '/'.ltrim($route->uri(), '/');
        $authenticated = collect($route->gatherMiddleware())
            ->contains(fn (mixed $m): bool => is_string($m) && str_contains($m, 'auth:sanctum'));

        foreach (array_diff($route->methods(), ['HEAD']) as $method) {
            $operation = $document['paths'][$path][strtolower($method)];

            expect(array_key_exists('security', $operation))->toBe($authenticated, $path);
        }
    }
});

it('declares every path parameter of every route', function () {
    $document = OpenApiDocument::build();

    foreach (OpenApiDocument::routes() as $route) {
        if ($route->parameterNames() === []) {
            continue;
        }

        $path = '/'.ltrim($route->uri(), '/');

        foreach (array_diff($route->methods(), ['HEAD']) as $method) {
            $names = array_column($document['paths'][$path][strtolower($method)]['parameters'] ?? [], 'name');

            expect($names)->toBe($route->parameterNames(), $path);
        }
    }
});

/*
|--------------------------------------------------------------------------
| The generator itself
|--------------------------------------------------------------------------
|
| The drift test above proves the file matches the generator. These prove the
| generator says something true — otherwise both halves could agree on the
| same wrong answer.
|
*/

it('carries the form request constraints a client has to satisfy', function () {
    $schema = OpenApiDocument::build()['paths']['/api/v1/auth/register']['post']['requestBody']['content']['application/json']['schema'];

    expect($schema['required'])->toEqualCanonicalizing(['name', 'email', 'password', 'company_name'])
        ->and($schema['properties']['email']['format'])->toBe('email')
        ->and($schema['properties']['email']['maxLength'])->toBe(255)
        ->and($schema['properties']['company_name']['maxLength'])->toBe(255);
});

it('reads an enum out of a Rule object', function () {
    $schema = ValidationRuleSchema::fromRules([
        'role' => ['required', Rule::in(['owner', 'admin', 'member'])],
    ]);

    expect($schema['properties']['role']['enum'])->toBe(['owner', 'admin', 'member'])
        ->and($schema['required'])->toBe(['role']);
});

it('treats sometimes plus required as an optional member', function () {
    $schema = ValidationRuleSchema::fromRules([
        'name' => ['sometimes', 'required', 'string', 'max:255'],
        'other' => ['required', 'string'],
    ]);

    expect($schema['required'])->toBe(['other'])
        ->and($schema['properties']['name']['maxLength'])->toBe(255);
});

it('nests dotted rules into an object', function () {
    $schema = ValidationRuleSchema::fromRules([
        'settings' => ['required', 'array'],
        'settings.app_id' => ['required', 'string', 'max:255'],
        'settings.hint' => ['sometimes', 'string'],
    ]);

    $settings = $schema['properties']['settings'];

    expect($settings['type'])->toBe('object')
        ->and(array_keys($settings['properties']))->toBe(['app_id', 'hint'])
        ->and($settings['required'])->toBe(['app_id']);
});

it('reads the status code and the body keys out of a controller action', function () {
    $scanned = ControllerResponses::for(
        ApiKeyController::class,
        'store',
    );

    expect(array_keys($scanned))->toBe([201])
        ->and(array_keys($scanned[201]['keys']))->toBe(['api_key', 'plain_text_token'])
        ->and($scanned[201]['keys']['api_key'])->toBe('ApiKeyResource');
});

it('reads a 204 out of an action that returns no content', function () {
    $scanned = ControllerResponses::for(
        ProfileController::class,
        'updatePassword',
    );

    expect(array_keys($scanned))->toBe([204]);
});

it('follows a private helper for the status a create actually returns', function () {
    // IntegrationController::store() delegates to single($integration, 201);
    // the json() call itself only says $status.
    $scanned = ControllerResponses::for(
        IntegrationController::class,
        'store',
    );

    expect(array_keys($scanned))->toBe([201])
        ->and(array_keys($scanned[201]['keys']))->toBe(['integration']);
});

it('extracts the field set of an api resource from its toArray', function () {
    expect(ResourceSchemas::fields(UserResource::class))
        ->toBe(['id', 'company_id', 'name', 'email', 'role', 'email_verified_at', 'two_factor_enabled', 'created_at']);
});

it('publishes a component schema for every api resource', function () {
    $schemas = OpenApiDocument::build()['components']['schemas'];

    foreach (ResourceSchemas::classes() as $class) {
        expect($schemas)->toHaveKey(class_basename($class));
    }
});

it('publishes the whole error code catalogue', function () {
    $enum = OpenApiDocument::build()['components']['schemas']['Error']['properties']['code']['enum'];

    expect($enum)->toBe(array_map(fn (ApiErrorCode $case): string => $case->value, ApiErrorCode::cases()));
});

it('lists 402 only where the quota middleware is applied', function () {
    $document = OpenApiDocument::build();

    foreach (OpenApiDocument::routes() as $route) {
        $path = '/'.ltrim($route->uri(), '/');
        $gated = collect($route->gatherMiddleware())
            ->contains(fn (mixed $m): bool => $m === 'quota');

        foreach (array_diff($route->methods(), ['HEAD']) as $method) {
            $responses = $document['paths'][$path][strtolower($method)]['responses'];

            expect(array_key_exists('402', $responses))->toBe($gated, $path);
        }
    }
});

/**
 * Invariant I5 reaches the contract document too. The check is on the
 * *component* schemas — the resource shapes, i.e. what the API gives back —
 * and not on the whole file, because `credentials` legitimately appears in the
 * integration request body: a client has to be able to send one. What must
 * never appear is a secret on the way out.
 */
it('never publishes a stored secret as a response member', function () {
    $document = OpenApiDocument::build();

    foreach ($document['components']['schemas'] as $name => $schema) {
        expect(array_keys($schema['properties'] ?? []))
            ->not->toContain('credentials', $name)
            ->and(array_keys($schema['properties'] ?? []))->not->toContain('token_hash', $name)
            ->and(array_keys($schema['properties'] ?? []))->not->toContain('two_factor_secret', $name)
            ->and(array_keys($schema['properties'] ?? []))->not->toContain('password', $name);
    }
});

it('resolves the contract path next to the repository contracts directory', function () {
    expect(OpenApiContract::path())->toEndWith('/contracts/'.OpenApiContract::FILENAME);
});

it('routes the same set the router holds', function () {
    $uris = array_map(fn (Route $route): string => $route->uri(), OpenApiDocument::routes());

    expect($uris)->not->toBeEmpty()
        ->and(collect($uris)->every(fn (string $uri): bool => str_starts_with($uri, 'api/')))->toBeTrue();
});
