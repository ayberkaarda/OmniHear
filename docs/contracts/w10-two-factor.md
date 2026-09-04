# W10 — TOTP two-factor contract

Written before dispatch. Backend (track A) and frontend (track B) both build
against this document and neither guesses at the other's shape.

## Why this phase exists

`UserResource.php:28` publishes `two_factor_enabled`, `auth.models.ts:19` types
it, and `User::twoFactorEnabled()` computes it from `two_factor_secret` — a
column that has existed since the tenancy migration and that **no code path can
write**. `composer.json` carries no TOTP package. The API therefore promises a
capability that is structurally always `false`. W10 makes the promise true.

Spec §7.1 lists 2FA as optional. Optional means it may be absent; it does not
mean it may be advertised and missing.

## Schema (already migrated — do not write another migration)

`2026_09_04_000001_add_two_factor_columns_to_users_table.php` added:

| column | type | meaning |
|---|---|---|
| `two_factor_confirmed_at` | `timestamptz` null | enrolment finished; **this** is what "enabled" means |
| `two_factor_recovery_codes` | `text` null, `encrypted` cast, JSON array of **hashes** | one-time fallbacks |

`two_factor_secret` (existing, `encrypted`) holds the base32 secret.

**`twoFactorEnabled()` changes meaning**: it must become
`$this->two_factor_confirmed_at !== null`, not `filled($this->two_factor_secret)`.
A secret alone means enrolment started. If "enabled" flipped at that moment, a
user who generated a secret and closed the tab would be locked out by an
enrolment they never completed.

## Token abilities

`TokenAbility` gains a third value alongside the existing session/API-key split.
A **challenge token** is issued when a password is correct but a second factor
is still owed. It grants exactly one thing: the right to call the challenge
endpoint.

- It must be recognised **positively** (`TokenAbility::isChallenge()`), never as
  "lacks the session ability". `docs/LESSONS.md` records that a legacy `['*']`
  token answers `true` to every `can()` check, so an absence test promotes
  wildcards instead of demoting them.
- Enforcement extends the existing `EnforceTokenAbility` middleware. **Do not add
  a new middleware**: `$middlewarePriority` has bitten this codebase four times
  (LESSONS 2026-09-02 ×2, 2026-09-03 ×2), and the existing middleware's position
  is already solved.
- Lifetime **5 minutes**. It is deleted on success, on failure past the attempt
  cap, and on expiry.

## Endpoints

All under the `v1` API. Errors are `{code, message}` (§6); every new `code` needs
`ApiErrorCode` plus `lang/en` and `lang/tr` entries.

### `POST /api/v1/auth/login` — changed

When the password is correct **and** `two_factor_confirmed_at` is set:

```
200 OK
{ "two_factor_required": true, "challenge_token": "<plain text token>" }
```

**Status is 200, not 401.** The frontend's error interceptor maps 401 to
`UNAUTHENTICATED` and tears the session down; a 401 here would log the user out
of the flow they are trying to enter. This is a successful first factor, not a
failure.

Unchanged when 2FA is off: the existing full-session response.

### `POST /api/v1/auth/two-factor/challenge` — new, public route

Authenticated by the challenge token in the `Authorization` header.

```
{ "code": "123456" }          // or
{ "recovery_code": "xxxx-xxxx" }
```

Exactly one of the two. Success returns the **same body `/auth/login` returns
today** for a completed login — same shape, same fields, so the frontend has one
success path.

- `422 TWO_FACTOR_CODE_INVALID` — wrong code or already-used recovery code.
- Rate limited under `throttle:public`, **plus** a per-token attempt counter; the
  challenge token dies when the counter is exhausted.
- A TOTP code that has already been accepted must be **rejected on reuse**: store
  the last accepted timestep on the user and refuse anything at or below it.
  Accepting the same code twice inside its window is a replay.

### `POST /api/v1/auth/two-factor` — new, session-authenticated

Begins enrolment. Generates a secret, stores it, returns it **once**:

```
201
{ "secret": "BASE32...", "otpauth_url": "otpauth://totp/...", "qr_svg_data_uri": "data:image/svg+xml;base64,..." }
```

QR is rendered **server-side** as SVG and delivered as a base64 data URI. It goes
in an `<img src>`, so the frontend needs no sanitizer bypass and the initial
bundle is untouched (Trap 2 — `bacon/bacon-qr-code` must not reach the browser).

Calling it again before confirmation replaces the unconfirmed secret. Calling it
while already confirmed is `409 TWO_FACTOR_ALREADY_ENABLED`.

### `POST /api/v1/auth/two-factor/confirm` — new, session-authenticated

```
{ "code": "123456" }
```

Sets `two_factor_confirmed_at` and returns the recovery codes **once**:

```
200
{ "recovery_codes": ["xxxx-xxxx", ... 8 total] }
```

`422 TWO_FACTOR_CODE_INVALID` on a bad code. Stored hashed; never returned again.

### `DELETE /api/v1/auth/two-factor` — new, session-authenticated

```
{ "password": "...", "code": "123456" }
```

Both required. Clears all three columns. Disabling a second factor is exactly
when an attacker on a stolen session would act, so it re-proves both factors.

### `POST /api/v1/auth/two-factor/recovery-codes` — new, session-authenticated

Regenerates and returns a fresh set, invalidating the old. Requires a current
code. Only when confirmed.

## Amended during the phase

Two shapes this document did not name, settled while the tracks were building
and recorded here so the document matches what was shipped:

- **`TWO_FACTOR_NOT_ENABLED`, HTTP 409.** Three endpoints need a "the account is
  not in a state where this makes sense" answer: confirming when nothing is
  pending, and regenerating codes or disabling when 2FA is not on. In practice it
  fires when a second tab or a stale screen acts on state that has already
  changed, so its message says what the state is rather than implying the caller
  did something wrong.
- **`DELETE /api/v1/auth/two-factor` answers 204**, following the convention
  `ProfileController::updatePassword` already set. The frontend does not read a
  body.

One deviation is deliberate and is **not** closed. The contract says to store the
last accepted timestep *on the user*; the migration was already written and
applied before dispatch and no track owned `database/migrations/`, so the
high-water mark lives in the cache instead, with a TTL matching the longest a
code stays verifiable. The cost is stated plainly: a cache eviction — or a
`FLUSHALL` — forgets the mark, and a code observed inside its own ~90-second
window could be replayed once.

Be precise about what survives that, because the first version of this paragraph
was not: the per-token attempt counter lives in the **same** cache, and
`throttle:public` is cache-backed as well, so losing the cache resets every
guess-limiting mechanism on this endpoint at once. What still stands is the
code's own expiry and the challenge token's five-minute row in the database.

The exposure is narrow — it needs the attacker to hold the password (a challenge
token cannot be minted without it), to have observed a code, for the victim to
have actually spent that code, and for the cache to be lost inside the same
two-minute window. Compose declares no `maxmemory` policy, so Redis defaults to
`noeviction` and a full cache errors on write rather than silently forgetting.

The reason to move it to `users.two_factor_last_used_step` is therefore not
durability but **atomicity**: the current check is read-then-write, so two
concurrent requests carrying the same code both pass. The column version is a
conditional `UPDATE … WHERE col IS NULL OR col < ?` with an affected-row check,
which closes the race as well. When it moves, `destroy()` and `store()` must
also clear the mark — otherwise disabling and immediately re-enrolling rejects
the new secret's first code as a replay of the old one's.

## Secrets and logging (invariant I5)

The secret appears in exactly one response body, at enrolment, and nowhere else —
not in `UserResource`, not in audit metadata, not in any log. Recovery codes
appear in exactly two (enrolment confirm, regeneration). `sensitive-log-guard`
watches for the rest.

`HttpOpenApiContractTest` has an assertion about never publishing a stored
secret. **Read that test's criterion before writing the enrolment response** — it
may need to distinguish "returns a freshly generated secret once" from "exposes a
stored one", and if it does, the test is what changes, with its reasoning
written down.

## Audit

`AuditAction` gains `TwoFactorEnabled`, `TwoFactorDisabled`, `TwoFactorChallengeFailed`.
Metadata carries no secret and no code.

## Frontend

- **Login** gains a second step in place, on the same route — the component
  switches on a signal, no new route. `/auth/two-factor` is not in spec §4's page
  tree.
- **Profile** gains a third section, following the two form patterns already
  there. Spec §4 lists no `security` route; adding one would be a deviation.
- Every string is `i18n`-marked and both `.xlf` files stay full (Trap 3 — today
  437/437, no empty `<target>`).
- `two_factor_enabled` in `auth.models.ts` keeps its name and now means confirmed.

## Testing

- TOTP correctness is proven against **RFC 6238 Appendix B test vectors** — known
  secret, fixed time. A test that generates the expected code with the same class
  it is testing proves only that the class agrees with itself.
- The ±1 window boundary and the reuse rejection are separate tests.
- Ability enforcement is proven with a **real bearer token on the wire**, not
  `actingAs()`. LESSONS 2026-09-03: `actingAs($user, 'sanctum')` installs a
  `TransientToken`, so an entire authorization boundary once had no test that
  travelled the path production travels — deleting the enforcement would have
  turned nothing red.
- The E2E journey gains a 2FA leg. Its TOTP helper is written with Node's
  `crypto` (RFC 6238 is HMAC-SHA1 over a counter); no new frontend dependency.
