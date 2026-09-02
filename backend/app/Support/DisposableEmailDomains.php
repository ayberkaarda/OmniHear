<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Spec 7.1: registration rejects throwaway mailboxes *and* free consumer
 * providers. Both lists live in config/registration.php so they can grow
 * without touching code.
 *
 * The two checks stay separate methods rather than collapsing into one list,
 * because they answer different questions and one of them is optional. A
 * disposable domain is abuse and is always refused; a free provider is a
 * business-policy call, switched by `registration.block_free_domains`.
 * `refuses()` is what registration calls — the only place that needs the
 * combined answer.
 */
class DisposableEmailDomains
{
    /**
     * The registration gate: either list is grounds for refusal.
     */
    public function refuses(string $email): bool
    {
        return $this->blocks($email) || $this->blocksFreeProvider($email);
    }

    /**
     * Throwaway mailbox domains. Always enforced.
     */
    public function blocks(string $email): bool
    {
        return $this->matches($email, (array) config('registration.disposable_domains', []));
    }

    /**
     * Free consumer mailbox providers — Gmail, Outlook and friends. Enforced
     * only while `registration.block_free_domains` is on.
     */
    public function blocksFreeProvider(string $email): bool
    {
        if (! config('registration.block_free_domains', true)) {
            return false;
        }

        return $this->matches($email, (array) config('registration.free_domains', []));
    }

    /**
     * A domain matches when it is listed, or is a subdomain of a listed one:
     * `mail.mailinator.com` is `mailinator.com`, while `notmailinator.com` is a
     * different registrable domain and is not.
     *
     * @param  array<int, mixed>  $domains
     */
    private function matches(string $email, array $domains): bool
    {
        $domain = Str::of($email)->afterLast('@')->lower()->trim()->value();

        if ($domain === '') {
            return false;
        }

        foreach ($domains as $blocked) {
            $blocked = strtolower((string) $blocked);

            if ($domain === $blocked || str_ends_with($domain, '.'.$blocked)) {
                return true;
            }
        }

        return false;
    }
}
