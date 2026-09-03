# ADR-0003 — Laravel 13, not the spec-mandated Laravel 11

- **Status:** Accepted (user approved, 2026-09-02)
- **Date:** 2026-09-02
- **Phase:** F1
- **Related spec:** §2 "Core Backend: Laravel 11 (PHP 8.3)" · §7.1 "Kayıtta e-posta doğrulaması zorunlu"

## Context

The spec fixes Laravel 11 in the "değiştirilemez kısıtlar" (immutable constraints) table.
During F1 the Composer advisory gate refused to install it. Three advisories were
confirmed against the Packagist advisory API, evaluated by version range against the
installed 11.56.1:

| Advisory | Affected | Fixed in |
|---|---|---|
| `PKSA-m5cs-t1y6-qpcs` — Temporary Signed URL Path Confusion | `<12.61.1` | 12.61.1+ / 13.12.0+ |
| `PKSA-3r5d-mb8f-1qw9` — CRLF injection in default email rule | `<12.60.0` | 12.60.0+ / 13.10.0+ |
| `PKSA-mdq4-51ck-6kdq` (CVE-2026-48019) — same CRLF, 9.x–11.x record | `>=11.0.0,<12.0.0` | no 11.x backport |

The decisive fact is not the advisories but the branch: **Laravel 11 security support
ended 2026-03-12** (verified against Laravel's official support table). No patch will
arrive. The spec was written while 11 was current and supported; the spec did not
change — the calendar did.

Both flaws sit on code paths this product will actually write. §7.1 mandates email
verification: verification links use temporary signed routes, and the registration form
uses the default `email` validation rule.

A secondary observation: the spec pairs "Laravel 11" with "PHP 8.3", but Laravel 11
supports PHP 8.2–8.4, so 8.3 is merely *permitted* there. Laravel 13 requires PHP
8.3–8.5 — it is the only major whose floor matches the spec's stated PHP version.

## Decision

Use **Laravel 13** (`^13.30`, locked at 13.30.1). Remove the `config.policy.advisories`
suppression block entirely — with 13.x there is nothing left to suppress.

## Alternatives considered

- **Stay on 11 with the three advisories suppressed.** Rejected: the branch is EOL, so
  the suppression is permanent rather than transitional, and `composer audit` stays red
  forever. On a public portfolio repository a reviewer running `composer audit` sees
  three silenced CVEs — the worst available signal.
- **Stay on 11 and mitigate in application code** (custom email rule, hand-rolled signed
  tokens). Rejected: `composer audit` is still red, and writing a signature scheme
  underneath the framework adds attack surface the spec never asked for.
- **Laravel 12.** Rejected: bug-fix support ended 2026-08-13, security-only until
  2027-02-27. It changes no dependency today but re-incurs the same upgrade in months.
- **Laravel 13.** Chosen. Current branch, security support into 2028 Q1, PHP 8.3 floor.

## Consequences

**Positive.** `composer audit` reports no advisories. No suppression block exists, so a
future advisory still trips the gate rather than being pre-silenced. PHP 8.3 becomes a
hard requirement, matching the spec's own PHP line.

**Negative / accepted debt.** Pest 3 → 4, PHPUnit 11 → 12, tinker 2 → 3. `phpunit/phpunit`
was dropped as a direct dependency (Pest 4 pulls and constrains it). `VerifyCsrfToken` is
renamed `PreventRequestForgery` — harmless at F1 (the middleware closure is empty) but
F2+ must use the new name.

**Open.** `config/session.php` and `config/cache.php` are still the Laravel 11 skeleton
shape and lack Laravel 13's hardening defaults (`session.serialization = 'json'`,
`cache.serializable_classes = false`). The framework falls back to its internal defaults,
so nothing is broken, but these files should be reconciled before F2.

**Spec erratum.** Applied as **E-1** in `docs/OMNIHEAR-SPEC.md`'s Errata section on
2026-09-03, with user approval. The original §2 line is left standing; the Errata
overrides it, so the difference between what was asked and what was built stays
readable side by side instead of being edited away.
