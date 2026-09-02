<?php

/*
|--------------------------------------------------------------------------
| The two copies of the recorded platform responses
|--------------------------------------------------------------------------
|
| The provenance copy lives in contracts/fixtures/platforms/<platform>/ and the
| copy the code loads lives in backend/tests/Fixtures/platforms/<platform>/. Two
| copies exist because infra/docker-compose.dev.yml mounts contracts/ read-only
| while config/connectors.php loads fixtures from under tests/ — see that file
| for the full reason.
|
| Two copies drift, and a drift is silent: the suite would keep passing against
| the loaded copy while the published provenance copy said something else. This
| test is what notices. It covers every platform directory rather than a named
| one, so a connector added later is included without touching this file.
|
| README.md is provenance documentation, not a recorded response, so it lives
| only in contracts/ and is excluded here.
|
*/

/**
 * @return list<string>
 */
function fixtureNames(string $directory): array
{
    $names = array_values(array_filter(
        scandir($directory) ?: [],
        static fn (string $name): bool => ! in_array($name, ['.', '..'], true)
            && str_ends_with($name, '.md') === false
            && is_file($directory.DIRECTORY_SEPARATOR.$name),
    ));

    sort($names);

    return $names;
}

function provenanceRoot(): string
{
    return base_path('../contracts/fixtures/platforms');
}

function loadedRoot(): string
{
    return rtrim((string) config('connectors.fixtures_path'), '/\\');
}

/**
 * @return list<string>
 */
function provenancePlatforms(): array
{
    if (! is_dir(provenanceRoot())) {
        return [];
    }

    return array_values(array_filter(
        scandir(provenanceRoot()) ?: [],
        static fn (string $name): bool => ! in_array($name, ['.', '..'], true)
            && is_dir(provenanceRoot().DIRECTORY_SEPARATOR.$name),
    ));
}

it('has a provenance copy to compare against', function () {
    if (! is_dir(provenanceRoot())) {
        $this->markTestSkipped(
            'contracts/ is not mounted here; see config/connectors.php for why the loaded copy lives under tests/.'
        );
    }

    expect(provenancePlatforms())->not->toBeEmpty();
});

it('keeps every recorded platform fixture identical in both locations', function () {
    if (! is_dir(provenanceRoot())) {
        $this->markTestSkipped('contracts/ is not mounted here.');
    }

    foreach (provenancePlatforms() as $platform) {
        $provenance = provenanceRoot().DIRECTORY_SEPARATOR.$platform;
        $loaded = loadedRoot().DIRECTORY_SEPARATOR.$platform;

        expect(is_dir($loaded))->toBeTrue("Platform {$platform} has no loaded copy under tests/Fixtures/platforms.");

        // Both directions: a file added to one side and not the other is the
        // same drift as a file whose bytes differ.
        expect(fixtureNames($loaded))->toBe(fixtureNames($provenance), "Platform {$platform}: file lists differ.");

        foreach (fixtureNames($provenance) as $name) {
            expect(file_get_contents($loaded.DIRECTORY_SEPARATOR.$name))
                ->toBe(
                    file_get_contents($provenance.DIRECTORY_SEPARATOR.$name),
                    "Platform {$platform}: {$name} differs between the two copies."
                );
        }
    }
});

it('records the provenance of every platform fixture directory', function () {
    if (! is_dir(provenanceRoot())) {
        $this->markTestSkipped('contracts/ is not mounted here.');
    }

    // A fixture set with no README is a fixture set nobody can tell apart from
    // a real capture — which is exactly the mistake D-06 was opened for.
    foreach (provenancePlatforms() as $platform) {
        expect(is_file(provenanceRoot().DIRECTORY_SEPARATOR.$platform.DIRECTORY_SEPARATOR.'README.md'))
            ->toBeTrue("Platform {$platform} has no README.md recording where its fixtures came from.");
    }
});
