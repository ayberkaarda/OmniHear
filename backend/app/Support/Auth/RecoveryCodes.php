<?php

namespace App\Support\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * The fallback for a user who has lost the device that holds the secret
 * (docs/contracts/w10-two-factor.md).
 *
 * # Why they are hashed
 *
 * A recovery code is a *complete* second factor on its own: whoever holds one
 * and the password is in. Storing them in the clear would mean a database copy
 * hands the attacker the very thing 2FA exists to withhold — the same argument
 * that makes `password` a hash and not a column you can read. So the column
 * holds hashes, the plaintext exists only in the two responses that mint it
 * (enrolment confirmation and regeneration), and it is never recoverable
 * afterwards. That is a feature, not a gap: "show me my codes again" and "let
 * an attacker read my codes" are the same request.
 *
 * The `encrypted:array` cast is on top of the hashing rather than instead of
 * it. Encryption protects a stolen dump against an attacker without APP_KEY;
 * hashing protects it against one who has both.
 *
 * # Single use
 *
 * A consumed code is removed from the stored set, not marked. There is nothing
 * to be gained from remembering a spent code, and a set that only shrinks makes
 * "how many are left" answerable by counting.
 */
final class RecoveryCodes
{
    public const COUNT = 8;

    /**
     * `xxxx-xxxx`: two groups of four from an unambiguous alphabet.
     *
     * No `l`/`1`/`0`/`o`. These are transcribed by hand off a screen or a
     * printout, often months later, and a code that cannot be read back is not
     * a recovery mechanism.
     */
    private const ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';

    /**
     * A fresh set of plaintext codes. Shown once; never stored in this form.
     *
     * @return list<string>
     */
    public function generate(): array
    {
        $codes = [];

        while (count($codes) < self::COUNT) {
            $code = $this->group().'-'.$this->group();

            // Duplicates inside one set would make the "single use" accounting
            // ambiguous: consuming one would leave an identical twin behind.
            if (! in_array($code, $codes, true)) {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    /**
     * Replace the stored set with the hashes of the given plaintext codes.
     *
     * @param  list<string>  $codes
     */
    public function store(User $user, array $codes): void
    {
        $user->forceFill([
            'two_factor_recovery_codes' => array_map(
                static fn (string $code): string => Hash::make($code),
                $codes,
            ),
        ])->save();
    }

    /**
     * Spend one code. True when it matched a stored hash, which is then gone.
     *
     * Every remaining hash is compared even after a match, so the work done is
     * the same for a hit and a miss: the number of hashes tried is otherwise a
     * side channel that says how close a guess was to the front of the list.
     */
    public function consume(User $user, string $code): bool
    {
        $code = trim($code);
        $stored = $this->stored($user);

        if ($code === '' || $stored === []) {
            return false;
        }

        $remaining = [];
        $spent = false;

        foreach ($stored as $hash) {
            if (! $spent && Hash::check($code, $hash)) {
                $spent = true;

                continue;
            }

            $remaining[] = $hash;
        }

        if ($spent) {
            $user->forceFill(['two_factor_recovery_codes' => $remaining])->save();
        }

        return $spent;
    }

    /**
     * @return list<string>
     */
    private function stored(User $user): array
    {
        $codes = $user->getAttribute('two_factor_recovery_codes');

        return is_array($codes) ? array_values(array_filter($codes, 'is_string')) : [];
    }

    private function group(): string
    {
        $group = '';

        for ($i = 0; $i < 4; $i++) {
            $group .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return $group;
    }
}
