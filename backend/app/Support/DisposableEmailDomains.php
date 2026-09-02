<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Spec 7.1: registration rejects throwaway mailboxes. The list lives in
 * config/registration.php so it can grow without touching code.
 */
class DisposableEmailDomains
{
    public function blocks(string $email): bool
    {
        $domain = Str::of($email)->afterLast('@')->lower()->trim()->value();

        if ($domain === '') {
            return false;
        }

        foreach ((array) config('registration.disposable_domains', []) as $blocked) {
            $blocked = strtolower((string) $blocked);

            if ($domain === $blocked || str_ends_with($domain, '.'.$blocked)) {
                return true;
            }
        }

        return false;
    }
}
