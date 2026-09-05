<?php

namespace Tests\Support\Architecture;

/**
 * Invariant I5, enforced statically: integrations.credentials is never logged,
 * and neither is KVKK-protected feedback PII.
 *
 * $hidden is not enough on its own. It hides an attribute from serialisation,
 * not from the raw attribute array, so `Log::info('...', $integration->toArray())`
 * still writes the ciphertext-decrypted value to disk. The rule is therefore
 * about the *call site*: a logging call on the same line as a sensitive name.
 *
 * Two lessons from the hook this replaces are carried over deliberately:
 *
 *  - Call sites need a boundary, not a substring test. Plain str_contains('ray(')
 *    matches `toArray(`, `in_array(` and `array(`; 'dd(' matches `add(`. That is
 *    not hypothetical - `toArray(Request $request)` is the mandatory signature
 *    of every Laravel API Resource, so the substring form fired on every one of
 *    them. Hence (?<![\w$])name\s*\( below.
 *  - `>` is deliberately absent from that lookbehind, so `$logger->info(...)`
 *    is still caught.
 *
 * The hook's *second*, unconditional check - dd/dump/var_dump/ray anywhere in
 * app/, sensitive name or not - is not reproduced here. It has a better home:
 * arch()->preset()->php(), see tests/Feature/Architecture/SensitiveLogArchTest.php.
 * Those four names stay in LOG_CALLS below because a debug call that prints a
 * credential is still a credential leak, and this rule reports it as one.
 */
final class SensitiveLogRule
{
    /**
     * Production PHP. tests/ is exempt - a test may assert *about* a secret,
     * and the fixtures that prove credentials never leak have to name them.
     *
     * @var list<string>
     */
    public const ROOTS = ['app', 'bootstrap', 'config', 'database', 'routes'];

    /**
     * Anything that can put a value somewhere durable.
     *
     * @var array<string, string> label => pattern
     */
    public const LOG_CALLS = [
        'Log::' => '/\bLog::/',
        'logger(' => '/(?<![\w$])logger\s*\(/',
        'report(' => '/(?<![\w$])report\s*\(/',
        'info(' => '/(?<![\w$])info\s*\(/',
        'error_log(' => '/(?<![\w$])error_log\s*\(/',
        'dd(' => '/(?<![\w$])dd\s*\(/',
        'dump(' => '/(?<![\w$])dump\s*\(/',
        'var_dump(' => '/(?<![\w$])var_dump\s*\(/',
        'ray(' => '/(?<![\w$])ray\s*\(/',
    ];

    /**
     * Names whose value must never reach a log line. Word-bounded, so
     * `context` does not trip `text`.
     *
     * The hook also listed '->credentials' separately; it is subsumed by the
     * \bcredentials\b entry and only ever produced the same finding twice.
     *
     * @var array<string, string> label => pattern
     */
    public const SENSITIVE = [
        'credentials' => '/\bcredentials\b/i',
        '2fa_secret' => '/\b2fa_secret\b/i',
        'raw_payload' => '/\braw_payload\b/i',
        'secret' => '/\bsecret\b/i',
        'api_key' => '/\bapi_?key\b/i',
        'password' => '/\bpassword\b/i',
        'access_token' => '/\baccess_token\b/i',
        '$integration' => '/\$integration\b/i',
    ];

    /**
     * Log calls that name a sensitive value on the same line.
     *
     * @return list<string>
     */
    public static function findings(string $relativePath, string $contents): array
    {
        $findings = [];

        foreach (SourceTree::lines($contents) as $index => $line) {
            // Prose describing the rule is not a call site. The hook read
            // comments too and had to be talked out of its own docblocks.
            if (SourceTree::isCommentLine($line)) {
                continue;
            }

            $call = self::firstMatch(self::LOG_CALLS, $line);

            if ($call === null) {
                continue;
            }

            $marker = self::firstMatch(self::SENSITIVE, $line);

            if ($marker === null) {
                continue;
            }

            $findings[] = $relativePath.':'.($index + 1).' - "'.$call.'" logs alongside "'.$marker.'"; '
                .'log an id or the correlation_id instead, or mask the value';
        }

        return $findings;
    }

    /**
     * @param  array<string, string>  $patterns
     */
    private static function firstMatch(array $patterns, string $line): ?string
    {
        foreach ($patterns as $label => $pattern) {
            if (preg_match($pattern, $line) === 1) {
                return $label;
            }
        }

        return null;
    }
}
