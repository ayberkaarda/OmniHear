# ADR-0002 — Containers are the authoritative runtime, not the host

- **Status:** Accepted
- **Date:** 2026-09-02
- **Phase:** F1
- **Related spec:** §2 (PHP 8.3, Python 3.12, PostgreSQL 16) · §10 (docker-compose.dev.yml)

## Context

The spec fixes exact runtime versions. The development host does not match them, and on
one axis cannot match them at all:

| Component | Spec | Host | Gap |
|---|---|---|---|
| PHP | 8.3 | 8.2.12 | version, **and** Windows has no `pcntl`/`posix` |
| Python | 3.12 | 3.14.7 | version |
| PostgreSQL | 16 | not installed | absent |

The `pcntl` gap is not cosmetic: `php artisan horizon` cannot run on this host under any
configuration, because the extension does not exist for Windows PHP builds. A queue
worker that cannot start is not a version mismatch, it is a missing capability.

The F1 backend workstream initially worked around dependency resolution by declaring fake
`ext-pcntl` / `ext-posix` versions in `config.platform`. That makes Composer resolve, but
it writes a falsehood into a file every environment reads — CI, containers, and any
reviewer.

## Decision

The container images defined under `infra/docker/` are the authoritative runtime. The
host is a convenience for editing, not a target.

- `infra/docker/backend.Dockerfile` — PHP 8.3, with `pcntl`, `posix`, `pdo_pgsql`,
  `redis`, `bcmath`, `intl`, `zip`, `opcache`. Verified: PHP 8.3.33, all eight present,
  `pcntl_fork` and `posix_getpid` callable.
- `infra/docker/ai-service.Dockerfile` — Python 3.12. Verified: the F1 test suite passes
  on real 3.12.14.
- `infra/docker-compose.dev.yml` — PostgreSQL 16, Redis 7, with health-gated dependencies.
- `composer.json` declares `config.platform.php = "8.3.0"` so resolution matches the
  container everywhere, including on the 8.2 host.
- The fake `ext-pcntl` / `ext-posix` platform entries were removed. Where a host install
  is genuinely needed, `--ignore-platform-req=ext-pcntl --ignore-platform-req=ext-posix`
  is documented in the backend README instead of lying in the manifest.

All regression-gate commands for the backend and the AI service run inside containers.
A green result on the host proves nothing about the spec's stated runtime.

## Alternatives considered

- **Install PHP 8.3 and PostgreSQL on the host.** Rejected: pushes setup work onto the
  user, and still cannot supply `pcntl` on Windows — Horizon would remain unrunnable.
- **Relax the spec to PHP 8.2.** Rejected: the spec's version is a real constraint and
  Laravel 13 requires 8.3 anyway (ADR-0003).
- **Keep the fake platform entries.** Rejected: a manifest that misstates the platform
  misleads CI and reviewers, and buys nothing — Horizon does not run on the host either way.

## Consequences

**Positive.** What the gate verifies is what the spec specifies. The isolation proof is
real: after a container test run, `omnihear` (dev) holds 0 tables while `omnihear_test`
holds the 9 created by `RefreshDatabase`.

**Negative.** Every backend command carries container ceremony (`docker run --network
omnihear_default -v … MSYS_NO_PATHCONV=1`). First `compose up` pays image build time.
Contributors need Docker.

**Follow-on.** Because the host toolchain is not authoritative, host-run results must not
be reported as gate evidence. `docs/PROGRESS.md` records the host/spec divergence as a
known deviation so it is not rediscovered.
