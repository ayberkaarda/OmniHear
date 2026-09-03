<?php

use App\Support\OpenApi\ControllerResponses;
use App\Support\OpenApi\OpenApiContract;
use App\Support\OpenApi\OpenApiDocument;
use App\Support\OpenApi\PhpArrayKeys;
use App\Support\OpenApi\ResourceSchemas;
use App\Support\OpenApi\ValidationRuleSchema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Tests\Support\OpenApi\ScannerFixtureController;
use Tests\Support\OpenApi\ScannerFixtureResource;

/**
 * The generator's edges.
 *
 * HttpOpenApiContractTest proves the committed file matches the generator and
 * that the generator gets this application right. This file proves it behaves
 * sanely on the shapes the application does not currently contain — a status
 * expression it cannot resolve, a helper that is public, a resource that builds
 * its payload indirectly. Those paths decide what a *future* endpoint gets
 * documented as, so leaving them unexercised would mean discovering them from a
 * wrong contract rather than from a red test.
 *
 * The fixtures live in tests/Support/OpenApi/.
 */

/*
|--------------------------------------------------------------------------
| ControllerResponses
|--------------------------------------------------------------------------
*/

it('answers nothing for an action that does not exist', function () {
    expect(ControllerResponses::for(ScannerFixtureController::class, 'noSuchAction'))->toBe([]);
});

it('answers nothing for a method with no readable source', function () {
    // An internal PHP method: reflection reports no file, so there is nothing
    // to read and inventing a shape would be worse than admitting it.
    expect(ControllerResponses::for(ArrayObject::class, 'count'))->toBe([]);
});

it('does not follow a public helper', function () {
    $scanned = ControllerResponses::for(ScannerFixtureController::class, 'usesPublicHelper');

    // 299 is the public helper's own status; picking it up would document one
    // endpoint with another's response.
    expect($scanned)->toBe([]);
});

it('follows a private helper', function () {
    $scanned = ControllerResponses::for(ScannerFixtureController::class, 'usesPrivateHelper');

    expect(array_keys($scanned))->toBe([202])
        ->and(array_keys($scanned[202]['keys']))->toBe(['from_private_helper']);
});

it('ignores a call to a method the class does not have', function () {
    $scanned = ControllerResponses::for(ScannerFixtureController::class, 'usesMissingHelper');

    expect(array_keys($scanned))->toBe([200])
        ->and(array_keys($scanned[200]['keys']))->toBe(['ok']);
});

it('is not confused by $this outside a method call', function (string $action) {
    $scanned = ControllerResponses::for(ScannerFixtureController::class, $action);

    expect(array_keys($scanned))->toBe([200]);
})->with(['usesInstanceOf', 'usesDynamicCall']);

it('falls back to 200 for a status expression it cannot resolve', function (string $action) {
    $scanned = ControllerResponses::for(ScannerFixtureController::class, $action);

    expect(array_keys($scanned))->toBe([200]);
})->with(['usesClassConstantStatus', 'usesClassFetchStatus']);

it('publishes no keys for a list body', function () {
    $scanned = ControllerResponses::for(ScannerFixtureController::class, 'returnsAList');

    expect($scanned[200]['keys'])->toBe([]);
});

/*
|--------------------------------------------------------------------------
| PhpArrayKeys
|--------------------------------------------------------------------------
*/

it('returns null past the end of the token stream', function () {
    expect(PhpArrayKeys::significant([], 0))->toBeNull();
});

it('reads only the top level keys of a nested literal', function () {
    $tokens = PhpArrayKeys::tokenize("['a' => ['b' => 1], 'c' => 2];");

    expect(array_keys(PhpArrayKeys::at($tokens, 0)))->toBe(['a', 'c']);
});

it('gives back the opening index for a bracket that never closes', function () {
    $tokens = PhpArrayKeys::tokenize("['a' => 1");

    expect(PhpArrayKeys::closingOf($tokens, 0))->toBe(0)
        ->and(PhpArrayKeys::closingOf(PhpArrayKeys::tokenize('[1];'), 0))->toBe(2);
});

/*
|--------------------------------------------------------------------------
| ResourceSchemas
|--------------------------------------------------------------------------
*/

it('answers no fields for a class with no toArray', function () {
    expect(ResourceSchemas::fields(stdClass::class))->toBe([]);
});

it('answers no fields when toArray does not return a literal', function () {
    expect(ResourceSchemas::fields(ScannerFixtureResource::class))->toBe([]);
});

/*
|--------------------------------------------------------------------------
| ValidationRuleSchema
|--------------------------------------------------------------------------
*/

it('maps the scalar rule vocabulary onto json schema', function () {
    $schema = ValidationRuleSchema::fromRules([
        'count' => ['required', 'integer', 'min:1', 'max:10'],
        'ratio' => ['required', 'numeric'],
        'flag' => ['required', 'boolean'],
        'ref' => ['required', 'uuid'],
        'when' => ['required', 'date'],
        'pin' => ['required', 'digits:4'],
        'slug' => ['required', 'string', 'regex:/^[a-z-]+$/i'],
        'maybe' => ['nullable', 'string'],
    ]);

    $properties = $schema['properties'];

    expect($properties['count'])->toMatchArray(['type' => 'integer', 'minimum' => 1, 'maximum' => 10])
        ->and($properties['ratio']['type'])->toBe('number')
        ->and($properties['flag']['type'])->toBe('boolean')
        ->and($properties['ref']['format'])->toBe('uuid')
        ->and($properties['when']['format'])->toBe('date-time')
        ->and($properties['pin']['pattern'])->toBe('^[0-9]{4}$')
        ->and($properties['slug']['pattern'])->toBe('^[a-z-]+$')
        ->and($properties['maybe']['nullable'])->toBeTrue();
});

it('ignores a bound that is not a number', function () {
    $schema = ValidationRuleSchema::fromRules(['thing' => ['required', 'string', 'max:none']]);

    expect($schema['properties']['thing'])->toBe(['type' => 'string']);
});

it('survives an empty enum and an unusable pattern', function () {
    $schema = ValidationRuleSchema::fromRules([
        'a' => ['required', 'in'],
        'b' => ['required', 'regex:/'],
    ]);

    expect($schema['properties']['a']['enum'])->toBe([])
        ->and($schema['properties']['b']['pattern'])->toBe('');
});

it('accepts a pipe delimited rule string', function () {
    $schema = ValidationRuleSchema::fromRules(['name' => 'required|string|max:20']);

    expect($schema['required'])->toBe(['name'])
        ->and($schema['properties']['name']['maxLength'])->toBe(20);
});

it('accepts a single rule object outside an array', function () {
    $schema = ValidationRuleSchema::fromRules(['role' => Rule::in(['a', 'b'])]);

    expect($schema['properties']['role']['enum'])->toBe(['a', 'b']);
});

it('names a rule object it cannot render as a string', function () {
    $schema = ValidationRuleSchema::fromRules([
        'password' => ['required', Password::min(12)],
        'custom' => ['required', new class
        {
            public bool $marker = true;
        }],
    ]);

    expect($schema['properties']['password']['description'])->toContain('password policy')
        ->and($schema['properties']['custom'])->toBe(['type' => 'string']);
});

it('carries the rules with no json schema equivalent into the description', function () {
    $schema = ValidationRuleSchema::fromRules([
        'platform' => ['prohibited'],
        'settings' => ['sometimes', 'array'],
        'settings.app_id' => ['required_with:settings', 'string'],
        'current_password' => ['required', 'current_password'],
        'password' => ['required', 'confirmed', 'different:current_password'],
    ]);

    expect($schema['properties']['platform']['description'])->toBe('Must not be sent.')
        ->and($schema['properties']['settings']['properties']['app_id']['description'])
        ->toContain('Required when its parent object is sent.')
        ->and($schema['properties']['current_password']['description'])
        ->toContain('current password')
        ->and($schema['properties']['password']['description'])
        ->toContain('_confirmation');
});

it('nests only one level per dotted segment', function () {
    $schema = ValidationRuleSchema::fromRules([
        'a' => ['required', 'array'],
        'a.b' => ['required', 'array'],
        'a.b.c' => ['required', 'string'],
    ]);

    expect(array_keys($schema['properties']['a']['properties']))->toBe(['b'])
        ->and(array_keys($schema['properties']['a']['properties']['b']['properties']))->toBe(['c']);
});

/*
|--------------------------------------------------------------------------
| Paths and wiring
|--------------------------------------------------------------------------
*/

it('types a uuid path parameter as one', function () {
    $parameters = OpenApiDocument::build()['paths']['/api/v1/notifications/{id}/read']['post']['parameters'];

    expect($parameters[0])->toBe([
        'name' => 'id',
        'in' => 'path',
        'required' => true,
        'schema' => ['type' => 'string', 'format' => 'uuid'],
    ]);
});

it('types a numeric path parameter as an integer', function () {
    $parameters = OpenApiDocument::build()['paths']['/api/v1/settings/team/{user}']['patch']['parameters'];

    expect($parameters[0]['schema'])->toBe(['type' => 'integer']);
});

it('documents 402 as soon as a route carries the quota middleware', function () {
    testApiRoute('get', 'quota-probe', fn () => response()->json(['ok' => true]), ['auth:sanctum', 'quota']);

    $responses = OpenApiDocument::build()['paths']['/api/v1/quota-probe']['get']['responses'];

    expect($responses)->toHaveKey('402')
        ->and($responses['402']['description'])->toContain('QUOTA_EXCEEDED');
});

it('says so rather than guessing when a route is a closure', function () {
    testApiRoute('get', 'closure-probe', fn () => response()->json(['ok' => true]));

    $responses = OpenApiDocument::build()['paths']['/api/v1/closure-probe']['get']['responses'];

    expect($responses['200']['description'])->toContain('closure');
});

it('honours an explicit contract path from the environment', function () {
    $original = getenv('HTTP_CONTRACT_PATH');

    try {
        putenv('HTTP_CONTRACT_PATH=/tmp/omnihear-contract-probe.json');

        expect(OpenApiContract::path())->toBe('/tmp/omnihear-contract-probe.json')
            ->and(OpenApiContract::read())->toBeNull();
    } finally {
        putenv(is_string($original) && $original !== '' ? 'HTTP_CONTRACT_PATH='.$original : 'HTTP_CONTRACT_PATH');
    }
});
