<?php

use App\Support\OpenApi\OpenApiContract;
use App\Support\OpenApi\OpenApiDocument;
use Illuminate\Support\Facades\Artisan;

use function Pest\Laravel\artisan;

/**
 * `php artisan openapi:export` — the command the drift test tells you to run.
 *
 * Worth its own tests for one reason: it is the recovery instruction printed by
 * a failing gate, so a broken command turns a one-line fix into a dead end.
 */
beforeEach(function () {
    $this->scratch = sys_get_temp_dir().'/omnihear-openapi-'.bin2hex(random_bytes(6)).'.json';
});

afterEach(function () {
    if (is_file($this->scratch)) {
        unlink($this->scratch);
    }
});

it('writes the document to the path it is given', function () {
    artisan('openapi:export', ['--output' => $this->scratch])->assertSuccessful();

    expect(file_get_contents($this->scratch))
        ->toBe(OpenApiContract::serialize(OpenApiDocument::build()));
});

it('passes --check against a file it just wrote', function () {
    artisan('openapi:export', ['--output' => $this->scratch])->assertSuccessful();

    artisan('openapi:export', ['--output' => $this->scratch, '--check' => true])->assertSuccessful();
});

it('fails --check when the committed file is missing', function () {
    artisan('openapi:export', ['--output' => $this->scratch, '--check' => true])->assertFailed();
});

it('fails --check when the committed file is stale', function () {
    file_put_contents($this->scratch, "{}\n");

    artisan('openapi:export', ['--output' => $this->scratch, '--check' => true])->assertFailed();
});

it('passes --check against the committed contract', function () {
    // The same assertion the drift test makes, through the command a failing
    // gate tells the reader to run.
    artisan('openapi:export', ['--check' => true])->assertSuccessful();
});

it('fails rather than pretending when the destination cannot be written', function () {
    // contracts/ is mounted read-only inside the dev container, which is the
    // real case this branch exists for; a directory that is not there produces
    // the same failure without depending on the mount.
    artisan('openapi:export', ['--output' => '/nonexistent-directory/http-api-v1.json'])->assertFailed();
});

it('prints the document to stdout for the host to redirect', function () {
    // Artisan::call rather than $this->artisan(): the PendingCommand helper
    // captures into its own buffer, and what this test is about is the bytes
    // that reach stdout for the shell to redirect.
    $status = Artisan::call('openapi:export', ['--print' => true]);

    expect($status)->toBe(0)
        ->and(Artisan::output())->toBe(OpenApiContract::serialize(OpenApiDocument::build()));
});
