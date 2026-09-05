<?php

use Tests\Support\Architecture\SensitiveLogRule;
use Tests\Support\Architecture\SourceTree;

/**
 * Invariant I5 as a static rule over the source tree: integrations.credentials
 * never reaches a log line, and neither does KVKK-protected feedback PII.
 *
 * tests/Feature/Tenancy/CredentialSecrecyTest.php and
 * tests/Feature/Ingestion/IntegrationSecrecyTest.php prove the runtime half -
 * the column is encrypted, $hidden keeps it out of responses, and a connector
 * failure writes a sync_error that names no secret. This file closes the gap
 * those cannot see: a log call that never runs in a test still ships.
 */

/*
|--------------------------------------------------------------------------
| Debug calls left in production code
|--------------------------------------------------------------------------
|
| pestphp/pest-plugin-arch was already a dependency and had never been used.
| Its php preset bans dump(), var_dump(), ray(), die(), echo, print(),
| print_r(), var_export(), goto and the xdebug_* family across the application
| namespace, which is a superset of what the old hook looked for - with one
| gap: `dd` is not in the preset's list (vendor/pestphp/pest/src/ArchPresets/
| Php.php), so it is named explicitly below.
|
| The preset is taken whole rather than narrowed. Measured before adopting it:
| the tree contains no exit/die and no echo/print, so nothing had to be
| weakened to make it pass.
|
*/

arch('production code carries no debug or output calls')
    ->preset()
    ->php();

arch('production code carries no dd(), which the preset does not cover')
    ->expect(['dd'])
    ->not->toBeUsed();

/*
|--------------------------------------------------------------------------
| The scan reaches something
|--------------------------------------------------------------------------
*/

it('scans the production source roots', function () {
    $files = SourceTree::phpFiles(SensitiveLogRule::ROOTS);

    expect($files)->not->toBeEmpty()
        ->and($files)->toHaveKey('app/Support/Connectors/IngestionRunner.php');
});

it('has both halves of the heuristic live in the tree', function () {
    // The rule only fires where a log call and a sensitive name meet on one
    // line. If either half stopped matching anything, "no findings" would mean
    // nothing at all - so assert both are present, separately.
    $logLines = 0;
    $sensitiveLines = 0;

    foreach (SourceTree::phpFiles(SensitiveLogRule::ROOTS) as $contents) {
        foreach (SourceTree::lines($contents) as $line) {
            if (SourceTree::isCommentLine($line)) {
                continue;
            }

            foreach (SensitiveLogRule::LOG_CALLS as $pattern) {
                if (preg_match($pattern, $line) === 1) {
                    $logLines++;
                    break;
                }
            }

            foreach (SensitiveLogRule::SENSITIVE as $pattern) {
                if (preg_match($pattern, $line) === 1) {
                    $sensitiveLines++;
                    break;
                }
            }
        }
    }

    expect($logLines)->toBeGreaterThan(0)
        ->and($sensitiveLines)->toBeGreaterThan(0);
});

/*
|--------------------------------------------------------------------------
| The rule, against the real tree
|--------------------------------------------------------------------------
*/

it('logs no credential and no PII', function () {
    $findings = [];

    foreach (SourceTree::phpFiles(SensitiveLogRule::ROOTS) as $path => $contents) {
        $findings = array_merge($findings, SensitiveLogRule::findings($path, $contents));
    }

    expect(implode("\n", $findings))->toBe('');
});

/*
|--------------------------------------------------------------------------
| The rule, against code written to break it
|--------------------------------------------------------------------------
*/

it('catches a log call that names a sensitive value', function (string $line) {
    expect(SensitiveLogRule::findings('app/Probe.php', $line))->toHaveCount(1);
})->with([
    'Log::info("sync failed", $integration->credentials);',
    'Log::error("bad key", [\'api_key\' => $key]);',
    'logger()->debug($user->password);',
    'report(new RuntimeException($integration->getAttribute(\'credentials\')));',
    'info("2fa", [\'2fa_secret\' => $secret]);',
    'error_log(json_encode($feedback->raw_payload));',
    // The lookbehind excludes word characters and `$` but deliberately not
    // `>`, so a call through an object still trips (docs/LESSONS.md).
    '$logger->info("token", [\'access_token\' => $token]);',
]);

it('stops complaining once the value is replaced by an identifier', function () {
    $offending = 'Log::warning("connector failed", [\'credentials\' => $integration->credentials]);';
    $fixed = 'Log::warning("connector failed", [\'integration_id\' => $id, \'correlation_id\' => $cid]);';

    expect(SensitiveLogRule::findings('app/Probe.php', $offending))->toHaveCount(1)
        ->and(SensitiveLogRule::findings('app/Probe.php', $fixed))->toBe([]);
});

it('needs both halves on the same line', function () {
    expect(SensitiveLogRule::findings('app/Probe.php', 'Log::info("started", [\'id\' => $id]);'))->toBe([])
        ->and(SensitiveLogRule::findings('app/Probe.php', '$this->credentials = $credentials;'))->toBe([]);
});

it('matches call sites on a boundary, not as a substring', function (string $line) {
    // docs/LESSONS.md: the hook once matched by plain substring, so `ray(`
    // fired on `toArray(` - which is the mandatory signature of every Laravel
    // API Resource - and `dd(` fired on `add(`. These lines all contain a
    // sensitive name; only a boundary-less matcher would call them log calls.
    expect(SensitiveLogRule::findings('app/Probe.php', $line))->toBe([]);
})->with([
    'public function toArray(Request $request): array => [\'password\' => null];',
    'if (in_array($secret, $codes, true)) {',
    'return $this->add($password);',
    '$hasher = new Hasher(array(\'secret\' => $secret));',
]);

it('does not read a log call out of prose', function () {
    $source = implode("\n", [
        '    /**',
        '     * Never Log::info($integration->credentials) - $hidden does not',
        '     * cover the raw attribute array.',
        '     */',
    ]);

    expect(SensitiveLogRule::findings('app/Probe.php', $source))->toBe([]);
});
