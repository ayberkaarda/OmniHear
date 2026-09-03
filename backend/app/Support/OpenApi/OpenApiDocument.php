<?php

namespace App\Support\OpenApi;

use App\Support\Http\ApiErrorCode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;

/**
 * The Laravel half of spec section 10: an OpenAPI document generated *from the
 * application*, never hand-written.
 *
 * The FastAPI side has had one since F3 (`contracts/ai-openapi.json`, exported
 * from the live Pydantic models with a drift test behind it). This is the same
 * arrangement for the HTTP API: `contracts/http-api-v1.json` is produced by
 * `php artisan openapi:export`, and
 * tests/Feature/Contract/HttpOpenApiContractTest.php turns "the application
 * changed and the contract file did not" into a red test.
 *
 * # Where each part comes from
 *
 * | part                        | source                                     |
 * |-----------------------------|--------------------------------------------|
 * | paths, methods, path params | the router's own route collection          |
 * | operationId, tags           | route names                                |
 * | security                    | the route's middleware stack               |
 * | request bodies              | the action's FormRequest `rules()`         |
 * | success statuses and bodies | static analysis of the controller action   |
 * | error responses             | middleware stack + the ApiErrorCode enum   |
 * | component schemas           | `toArray()` of every API Resource          |
 *
 * Nothing in that table is a list maintained by hand, which is the property
 * that makes the drift test worth having.
 *
 * # What it deliberately does not express
 *
 * - Member *types* inside a resource. See ResourceSchemas for why guessing them
 *   would make the document less trustworthy rather than more.
 * - Query parameters. `page` and `per_page` are read with `$request->integer()`
 *   inside the action; there is no declaration to read, and inferring them from
 *   a `paginate()` call would be a guess about which routes accept them.
 * - Which of the catalogued errors a given action can actually raise. The
 *   listing is derived from the middleware stack, so it is a superset: every
 *   error listed can occur on that route, but a route may never in practice
 *   reach, say, a 404.
 *
 * Those three gaps are stated in the document itself, in `info.description`,
 * so a reader of the file alone is not misled.
 */
final class OpenApiDocument
{
    public const VERSION = '1.0.0';

    /**
     * @return array<string, mixed>
     */
    public static function build(): array
    {
        $paths = [];

        foreach (self::routes() as $route) {
            $uri = '/'.ltrim($route->uri(), '/');

            foreach (self::methods($route) as $method) {
                $paths[$uri][$method] = self::operation($route, $method);
            }
        }

        ksort($paths);

        foreach ($paths as $uri => $operations) {
            ksort($operations);
            $paths[$uri] = $operations;
        }

        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'OmniHear HTTP API',
                'version' => self::VERSION,
                'description' => self::description(),
            ],
            'servers' => [
                ['url' => '/', 'description' => 'Relative to the deployment host.'],
            ],
            'paths' => $paths,
            'components' => [
                'securitySchemes' => [
                    'bearerAuth' => [
                        'type' => 'http',
                        'scheme' => 'bearer',
                        'description' => 'Sanctum personal access token: `Authorization: Bearer <token>`.',
                    ],
                ],
                'schemas' => array_merge(self::baseSchemas(), ResourceSchemas::all()),
            ],
        ];
    }

    private static function description(): string
    {
        return implode("\n", [
            'Generated from the Laravel application by `php artisan openapi:export`.',
            'Never edit this file by hand: tests/Feature/Contract/HttpOpenApiContractTest.php fails when it stops matching the application.',
            '',
            'The prose contracts in docs/contracts/ remain authoritative for member types,',
            'for the pagination query parameters (`page`, `per_page`), and for which of the',
            'catalogued errors a given endpoint raises in practice - the error listings below',
            'are derived from each route middleware stack and are therefore a superset.',
        ]);
    }

    /**
     * @return list<Route>
     */
    public static function routes(): array
    {
        $routes = [];

        foreach (Router::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/')) {
                continue;
            }

            $routes[] = $route;
        }

        usort($routes, fn (Route $a, Route $b): int => [$a->uri(), $a->methods()[0]] <=> [$b->uri(), $b->methods()[0]]);

        return $routes;
    }

    /**
     * @return list<string>
     */
    private static function methods(Route $route): array
    {
        return array_values(array_map(
            'strtolower',
            array_diff($route->methods(), ['HEAD']),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private static function operation(Route $route, string $method): array
    {
        $operation = [
            'operationId' => self::operationId($route, $method),
            'tags' => [self::tag($route)],
        ];

        $parameters = self::parameters($route);

        if ($parameters !== []) {
            $operation['parameters'] = $parameters;
        }

        if (self::isAuthenticated($route)) {
            $operation['security'] = [['bearerAuth' => []]];
        }

        $body = self::requestBody($route);

        if ($body !== null && in_array($method, ['post', 'put', 'patch'], true)) {
            $operation['requestBody'] = $body;
        }

        $operation['responses'] = self::responses($route);

        return $operation;
    }

    private static function operationId(Route $route, string $method): string
    {
        $name = $route->getName();

        if (is_string($name) && $name !== '') {
            $base = str_replace(['api.v1.', '.', '-'], ['', '_', '_'], $name);
        } else {
            $base = str_replace(['api/', '/', '{', '}', '-'], ['', '_', '', '', '_'], $route->uri());
        }

        // Two verbs on one path (PUT and PATCH on an integration) would
        // otherwise share an operationId, which is not allowed.
        return count(self::methods($route)) > 1 ? $base.'_'.$method : $base;
    }

    private static function tag(Route $route): string
    {
        $segments = explode('/', $route->uri());

        if (($segments[1] ?? null) !== 'v1') {
            return $segments[1] ?? 'root';
        }

        return $segments[2] ?? 'v1';
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function parameters(Route $route): array
    {
        $parameters = [];

        foreach ($route->parameterNames() as $name) {
            $pattern = $route->wheres[$name] ?? null;

            $schema = ['type' => 'string'];

            if ($pattern === '[0-9]+') {
                $schema = ['type' => 'integer'];
            } elseif (is_string($pattern) && str_contains($pattern, 'a-fA-F')) {
                $schema = ['type' => 'string', 'format' => 'uuid'];
            }

            $parameters[] = [
                'name' => $name,
                'in' => 'path',
                'required' => true,
                'schema' => $schema,
            ];
        }

        return $parameters;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function requestBody(Route $route): ?array
    {
        $action = self::action($route);

        if ($action === null) {
            return null;
        }

        foreach ($action->getParameters() as $parameter) {
            $type = $parameter->getType();

            if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }

            $class = $type->getName();

            if (! is_subclass_of($class, FormRequest::class)) {
                continue;
            }

            try {
                /** @var FormRequest $instance */
                $instance = new $class;
                $rules = $instance->rules();
            } catch (Throwable) {
                // A form request whose rules() needs a resolved route or a
                // request body cannot be introspected outside a request. Saying
                // so is better than publishing an empty object that reads as
                // "this endpoint takes nothing".
                return [
                    'required' => true,
                    'description' => 'Validated by '.$class.', whose rules() depends on request context and could not be introspected.',
                    'content' => ['application/json' => ['schema' => ['type' => 'object']]],
                ];
            }

            return [
                'required' => true,
                'description' => 'Validated by '.$class.'.',
                'content' => [
                    'application/json' => ['schema' => ValidationRuleSchema::fromRules($rules)],
                ],
            ];
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private static function responses(Route $route): array
    {
        $responses = [];

        foreach (self::successes($route) as $status => $body) {
            $responses[(string) $status] = $body;
        }

        foreach (self::errors($route) as $status => $description) {
            $responses[(string) $status] = [
                'description' => $description,
                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]],
            ];
        }

        ksort($responses);

        return $responses;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function successes(Route $route): array
    {
        $action = self::action($route);

        if ($action === null) {
            return [
                200 => [
                    'description' => 'Success. This route is a closure, so no body shape could be derived.',
                    'content' => ['application/json' => ['schema' => ['type' => 'object']]],
                ],
            ];
        }

        $scanned = ControllerResponses::for($action->class, $action->name);

        if ($scanned === []) {
            return [
                200 => [
                    'description' => 'Success. The action body could not be read statically; see docs/contracts/.',
                    'content' => ['application/json' => ['schema' => ['type' => 'object']]],
                ],
            ];
        }

        $responses = [];

        foreach ($scanned as $status => $shape) {
            if ($status === 204 || $shape['keys'] === []) {
                $responses[$status] = ['description' => $status === 204 ? 'No content.' : 'Success.'];

                continue;
            }

            $responses[$status] = [
                'description' => 'Success.',
                'content' => [
                    'application/json' => ['schema' => self::bodySchema($shape['keys'])],
                ],
            ];
        }

        return $responses;
    }

    /**
     * @param  array<string, string|null>  $keys
     * @return array<string, mixed>
     */
    private static function bodySchema(array $keys): array
    {
        $properties = [];

        foreach ($keys as $key => $resource) {
            $reference = $resource !== null && array_key_exists($resource, ResourceSchemas::all())
                ? ['$ref' => '#/components/schemas/'.$resource]
                : null;

            $properties[$key] = match (true) {
                $key === 'meta' => ['$ref' => '#/components/schemas/PaginationMeta'],
                $key === 'data' => ['type' => 'array', 'items' => $reference ?? (object) []],
                $reference !== null => $reference,
                default => (object) [],
            };
        }

        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => array_keys($properties),
        ];
    }

    /**
     * The catalogued errors a route can raise, derived from its middleware.
     *
     * @return array<int, string>
     */
    private static function errors(Route $route): array
    {
        $middleware = $route->gatherMiddleware();
        $errors = [];

        $add = function (ApiErrorCode $code) use (&$errors): void {
            $errors[$code->status()] = isset($errors[$code->status()])
                ? $errors[$code->status()].' / '.$code->value
                : $code->value;
        };

        if (self::isAuthenticated($route)) {
            $add(ApiErrorCode::Unauthenticated);
            $add(ApiErrorCode::Forbidden);
            $add(ApiErrorCode::NotFound);
        }

        if (self::uses($middleware, 'verified', 'EnsureEmailIsVerified')) {
            $add(ApiErrorCode::EmailNotVerified);
        }

        if (self::uses($middleware, 'quota', 'EnforceQuota')) {
            $add(ApiErrorCode::QuotaExceeded);
        }

        if (self::uses($middleware, 'throttle', 'ThrottleRequests')) {
            $add(ApiErrorCode::TooManyRequests);
        }

        if (self::requestBody($route) !== null) {
            $add(ApiErrorCode::ValidationError);
        }

        $add(ApiErrorCode::ServerError);

        ksort($errors);

        return $errors;
    }

    private static function isAuthenticated(Route $route): bool
    {
        foreach ($route->gatherMiddleware() as $entry) {
            if (is_string($entry) && (str_contains($entry, 'auth:sanctum') || str_contains($entry, 'Authenticate:sanctum'))) {
                return true;
            }
        }

        return false;
    }

    /**
     * `gatherMiddleware()` returns what the route *declared*, which for this
     * application is aliases (`verified`, `quota`, `throttle:api`) — the
     * resolved class names only appear in `route:list`, which does its own
     * lookup. Matching on the class name alone therefore found nothing and
     * quietly dropped 403 EMAIL_NOT_VERIFIED and 429 from every operation. Both
     * forms are accepted so the check survives an alias being removed.
     *
     * The alias is matched on the part before the first colon, so `quota` does
     * not also match `quota.header` — QuotaRemainingHeader is on every
     * authenticated route and raises nothing.
     *
     * @param  array<int, mixed>  $middleware
     */
    private static function uses(array $middleware, string $alias, string $class): bool
    {
        foreach ($middleware as $entry) {
            if (is_string($entry) && (explode(':', $entry, 2)[0] === $alias || str_contains($entry, $class))) {
                return true;
            }
        }

        return false;
    }

    private static function action(Route $route): ?ReflectionMethod
    {
        $controller = $route->getAction('controller');

        if (! is_string($controller) || ! str_contains($controller, '@')) {
            return null;
        }

        [$class, $method] = explode('@', $controller, 2);

        return method_exists($class, $method) ? new ReflectionMethod($class, $method) : null;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function baseSchemas(): array
    {
        return [
            'Error' => [
                'type' => 'object',
                'description' => 'The envelope of every non-2xx /api/v1 response (docs/contracts/http-api-v1.md section 2).',
                'properties' => [
                    'code' => [
                        'type' => 'string',
                        'enum' => array_map(fn (ApiErrorCode $case): string => $case->value, ApiErrorCode::cases()),
                    ],
                    'message' => ['type' => 'string'],
                    'errors' => [
                        'type' => 'object',
                        'description' => 'Present only for VALIDATION_ERROR: field name to list of messages.',
                        'additionalProperties' => ['type' => 'array', 'items' => ['type' => 'string']],
                    ],
                ],
                'required' => ['code', 'message'],
            ],
            'PaginationMeta' => [
                'type' => 'object',
                'properties' => [
                    'current_page' => ['type' => 'integer'],
                    'per_page' => ['type' => 'integer'],
                    'total' => ['type' => 'integer'],
                    'last_page' => ['type' => 'integer'],
                ],
                'required' => ['current_page', 'per_page', 'total', 'last_page'],
            ],
        ];
    }
}
