<?php

/*
|--------------------------------------------------------------------------
| The two copies of the recorded App Store responses
|--------------------------------------------------------------------------
|
| The provenance copy lives in contracts/fixtures/platforms/appstore/ and the
| copy the code loads lives in backend/tests/Fixtures/platforms/appstore/. Two
| copies exist because infra/docker-compose.dev.yml bind-mounts only ../backend
| into the backend container, so contracts/ does not exist at run time and
| nothing inside the container can read it.
|
| Two copies drift. This test is what notices when they do — on any run where
| contracts/ is reachable. Inside the container it is not, so the test skips
| rather than failing, and says so.
|
*/

it('keeps the recorded App Store fixtures identical in both locations', function () {
    $provenance = base_path('../contracts/fixtures/platforms/appstore');

    if (! is_dir($provenance)) {
        $this->markTestSkipped(
            'contracts/ is not mounted here; see config/connectors.php for why the loaded copy lives under tests/.'
        );
    }

    $loaded = rtrim((string) config('connectors.fixtures_path'), '/\\').DIRECTORY_SEPARATOR.'appstore';

    $names = collect(scandir($provenance) ?: [])->reject(fn (string $name) => in_array($name, ['.', '..'], true));

    expect($names)->not->toBeEmpty();

    foreach ($names as $name) {
        expect(file_get_contents($loaded.DIRECTORY_SEPARATOR.$name))
            ->toBe(file_get_contents($provenance.DIRECTORY_SEPARATOR.$name));
    }
});
