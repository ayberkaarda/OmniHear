# ADR-0011 — Security posture and deferred hardening

- **Status:** Accepted
- **Date:** 2026-09-05
- **Phase:** W14
- **Related spec:** §8 (security requirements), §7 (auth/quota), §3.6 (observability)
- **Related:** `docs/PROGRESS.md` D-14; `docs/LESSONS.md`

## Context

The whole codebase was reviewed adversarially — not "do the tests pass" but
"what can an attacker who holds a legitimate account on company A reach". Two
read-only reviews ran over the server-side surface and the frontend / analyzer /
secret surface; every load-bearing finding was re-verified against the tree
before it was acted on, and one that a review marked confirmed was demoted after
re-testing showed it did not reproduce as claimed.

This document is the durable record of that review: what was probed and found
sound, what was fixed in W14, and what was deliberately deferred with the trigger
and the shape of each deferred fix. It exists because a public portfolio
repository is read, and a reader deserves to see both that the crown-jewel
invariants hold and that the gaps were found rather than missed.

The lens throughout is **D-09**: this project is never deployed. An attack that
needs a production environment to matter (a cloud-metadata endpoint, a real
ingress) is weighted below one visible in the code itself, and a fix that
demonstrably closes a real hole is worth more as a portfolio signal than one that
guards infrastructure that does not exist.

## Probed and found sound

These were attacked, not assumed. They are the reason the review is worth
publishing.

- **Tenant isolation (I1).** Every `DB::table` / `->toBase()` / `withoutGlobalScope`
  bypass was audited individually; each carries a `// tenant-scope: bypass-ok`
  whose reason is sound (a primary-key address on the scope-exempt `companies`
  row, a per-row `company_id` bind, a token-scoped invitation lookup). Route-model
  binding runs `SetTenantContext` before `SubstituteBindings` (the
  `prependToPriorityList` in `bootstrap/app.php`), the KPI cache key derives from
  `TenantContext`, not from the request, and no controller mass-assigns
  `company_id`, `role`, `plan` or `quota_limit` from a request body.
- **The HMAC internal call (I7).** The body is encoded once and the same bytes are
  signed and sent; the analyzer verifies the raw body before parsing, with
  `hmac.compare_digest`; the secret has no default and the service refuses to
  start without it. The correlation id is server-generated for analysis jobs, so
  it cannot be injected, and it carries no authority.
- **The Sanctum ability boundary.** API keys answer 403 on every session-only
  route (account deletion, key minting, team, billing, the private channel); the
  challenge token reaches only the challenge endpoint; the `['*']` legacy-token
  trap is closed by a positive `isApiKey()` literal match rather than a `can()`
  check.
- **Injection.** No `selectRaw` takes request input — every column is a constant
  literal or drawn from a fixed enum; the `q` filter escapes LIKE metacharacters;
  `raw_payload` is never serialized and is masked.
- **Webhook signatures (I3).** Both providers verify the raw request body with a
  constant-time compare before any state change, fail closed on an empty secret,
  and replay is a database-unique no-op.

## Fixed in W14

Each of these was confirmed against the tree, then closed with a test that fails
before the fix and passes after.

| finding | fix | test |
|---|---|---|
| **SSRF** — a tenant-pasted `instance_url` / `session_url` was fetched with redirects followed and no host validation, so a redirect to `169.254.169.254` or `127.0.0.1:*` was followed | `App\Support\Connectors\OutboundHostPolicy` checks the host before the request and, via Guzzle `on_redirect`, before every hop: https only, and the host must resolve entirely to public addresses; loopback, link-local, RFC 1918 and the other reserved ranges are `Misconfigured`, an unresolvable host is `Unreachable`. Redirects stay on because JMAP `/.well-known/jmap` autodiscovery needs them | `OutboundHostPolicyTest` (table over the ranges, injectable resolver) + a per-connector test that a redirect-to-internal is refused and the second request never leaves |
| **2FA challenge counter** — non-atomic `Cache::get`+`Cache::put`, refillable by a fresh login, no per-account limit | moved to a `users` column, atomic conditional `UPDATE`, per-account; `issue()` revokes prior challenge tokens | `TwoFactorChallengeAttemptRaceTest` (five forked processes, caps at five) |
| **pro plan quota was `null`** — a paid activation left `quota_limit` at 200, so the customer still got 402 | `config/quota.php` `'pro' => 5000` | activation test raises the limit and drains the backlog |
| **password asymmetry** — 2FA *disable* required the password but 2FA *enrol* and email change did not, so a stolen session could persist itself | `current_password` on enrol and on an email change; a name-only profile update still needs no password | three cases, including the name-only pass-through |
| **Stripe tolerance fail-open** — a non-numeric env silently disabled the timestamp check | fail closed (`max(1, …)`) | test |
| **validation error echoed input** — `str(exc)` embedded the offending value in the response body | `exc.errors(include_input=False)`, in both the live `analyze.py` path and the dead-code `main.py` handler | response-body marker test |
| **PII mask residue** — `ahmet@sirket.com.tr` masked to `[email].tr` | domain character class `[\w.-]` | multi-label-TLD case |

## Deferred, with trigger and shape

None of these is a hole left open by oversight. Each is recorded because D-09
makes it non-urgent, and each names what would make it urgent and the one-line
shape of the fix, so a future maintainer picks it up rather than rediscovers it.

- **B2 — Horizon dashboard has no `Horizon::auth`.** Open on `local`, which is the
  only environment. *Trigger:* any environment that is not `local`. *Fix:* a
  `Horizon::auth(fn ($r) => $r->user()?->isOwner() === true)` in a service
  provider, environment-independent.
- **B6 — per-platform rate limiter is shared across tenants**, so one tenant with
  many integrations can starve another's ingestion. *Trigger:* multi-tenant load,
  or an abuse report. *Fix:* key the limiter on `platform + company_id`, with a
  second global layer for the third party's real limit; cap integrations per plan.
- **B10 — a reserved quota unit is not released when a worker is SIGKILLed** (costs
  the customer, never grants free analyses). *Trigger:* observed quota drift. *Fix:*
  attach the reservation to the feedback row and release it in `failed()`.
- **B14 — register/forgot-password enumeration.** `register` reports a taken email;
  `forgot-password` is timing-distinguishable because the notification is not
  queued. *Trigger:* before exposing sign-up to untrusted traffic. *Fix:* queue the
  notification (constant time) and return the same response for known/unknown.
- **B15 — no `TrustProxies`.** Good today (`X-Forwarded-For` is unspoofable), a
  landmine behind the first reverse proxy, where every client would share the
  proxy's IP and one attacker could throttle the whole API. *Trigger:* the first
  ingress/LB. *Fix:* configure `TrustProxies` before it is introduced.
- **B16 — `/broadcasting/auth` has no `throttle`.** *Trigger:* abuse of the channel
  auth endpoint. *Fix:* add the `throttle:api` the other authenticated routes carry.
- **B17 — the compose file ships placeholder secrets** (`dev-only-not-a-real-secret`,
  the Reverb key). They are `${VAR:-…}` defaults, named as placeholders, and a
  public dev-compose default is standard — but *trigger:* production. *Fix:* reject
  the `dev-only-*` literals when `APP_ENV=production`, alongside the existing
  placeholder rejection in `ai-service/app/config.py`.
- **Frontend hardening (no production frontend exists yet).** The bearer token is in
  `localStorage`, XSS-readable — no active XSS was found, but the second layer is
  absent. There is no Content-Security-Policy and no security headers, and no
  production Dockerfile/nginx to carry them. *Trigger:* a real frontend deployment.
  *Fix:* an httpOnly/SameSite cookie or BFF for the token; `Content-Security-Policy`,
  `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy` on
  the serving layer.
- **DNS rebinding (residual of the SSRF fix).** `OutboundHostPolicy` re-checks every
  redirect hop, which closes the redirect vector, but a host that resolves to a
  public address at check time and a private one at fetch time is not closed —
  that needs `CURLOPT_RESOLVE` pinning with a hand-rolled redirect loop, which
  `Http::fake` cannot exercise and which needs a live target to prove. *Trigger:*
  a real deployment reachable from untrusted networks. *Fix:* pin the resolved IP
  for the life of the request.

## Alternatives considered

The alternative to this document was to fix the top findings and leave the rest
implicit. Rejected for the same reason ADR-0010 exists: a public repository that
silently omits its known gaps is indistinguishable from one that never looked,
and the review's most valuable output for a reader is not the six fixes but the
evidence that the crown-jewel invariants were attacked and held.

Fixing everything now was also rejected. The deferred set is dominated by
production-shaped controls (trusted proxy, CSP, Horizon auth, DNS rebinding)
whose absence cannot be demonstrated or tested without an environment D-09 says
will not exist, so building them would be speculative infrastructure with nothing
to validate it against — the same trap ADR-0010 names.

## Consequences

**Positive.** The security posture is one durable, checkable document. Re-running
the review's greps and reading the fixed tests re-verifies the "sound" and
"fixed" columns; the deferred list is a work order with triggers rather than a
vague "harden later".

**Negative / accepted.** This is a snapshot dated 2026-09-05. New surface drifts
it; nothing re-checks it automatically. It is correct as of this phase and needs a
manual re-audit if the project is ever taken toward deployment — which is exactly
the trigger most deferred items already name.

## Related spec section

`docs/OMNIHEAR-SPEC.md` §8 (security), §7 (auth/quota), §3.6 (observability).
