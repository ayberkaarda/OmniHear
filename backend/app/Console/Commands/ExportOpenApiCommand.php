<?php

namespace App\Console\Commands;

use App\Support\OpenApi\OpenApiContract;
use App\Support\OpenApi\OpenApiDocument;
use Illuminate\Console\Command;

/**
 * Export the HTTP API OpenAPI document (spec section 10).
 *
 *   php artisan openapi:export --print > contracts/http-api-v1.json
 *   php artisan openapi:export --check
 *
 * # Why --print exists
 *
 * The dev compose stack mounts `../contracts` into the backend container
 * **read-only** (infra/docker-compose.dev.yml), because the backend consumes
 * the shared fixtures and must never write them. That mount is also the only
 * place the contract file exists inside the container, so the command cannot
 * write it in place — and infra/ is not this workstream's to change. `--print`
 * puts the document on stdout so the host redirects it into the file, and
 * `--check` reads the committed copy through the same read-only mount.
 *
 * Writing to a path given with --output still works wherever the file is
 * writable (a checkout running outside the container).
 */
class ExportOpenApiCommand extends Command
{
    protected $signature = 'openapi:export
        {--check : Exit non-zero when the committed contract no longer matches the application}
        {--print : Write the document to stdout instead of to a file}
        {--output= : Path to write to (default: the repository contracts/http-api-v1.json)}';

    protected $description = 'Generate contracts/http-api-v1.json from the route table, form requests and resources';

    public function handle(): int
    {
        $payload = OpenApiContract::serialize(OpenApiDocument::build());

        if ($this->option('print')) {
            $this->output->write($payload);

            return self::SUCCESS;
        }

        $path = (string) ($this->option('output') ?: OpenApiContract::path());

        if ($this->option('check')) {
            return $this->check($path, $payload);
        }

        if (@file_put_contents($path, $payload) === false) {
            $this->components->error(
                'Could not write '.$path.'. Inside the dev container contracts/ is mounted read-only; '
                .'run `php artisan openapi:export --print > contracts/http-api-v1.json` from the host instead.'
            );

            return self::FAILURE;
        }

        $this->components->info('Wrote '.$path.' ('.strlen($payload).' bytes)');

        return self::SUCCESS;
    }

    private function check(string $path, string $payload): int
    {
        if (! is_file($path)) {
            $this->components->error('MISSING: '.$path.'. Run: php artisan openapi:export --print > contracts/http-api-v1.json');

            return self::FAILURE;
        }

        if (OpenApiContract::read($path) !== $payload) {
            $this->components->error(
                'STALE: '.$path.' does not match the application. '
                .'Run: php artisan openapi:export --print > contracts/http-api-v1.json'
            );

            return self::FAILURE;
        }

        $this->components->info('OK: '.basename($path).' matches the application.');

        return self::SUCCESS;
    }
}
