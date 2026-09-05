# ADR-0001 — Single monorepo, not three repositories

- **Status:** Accepted
- **Date:** 2026-09-02
- **Phase:** F1
- **Related spec:** §10 "Monorepo veya 3 repo (frontend / core / ai) — gerekçesiyle öner"

## Context

The spec leaves this open and asks for a justified recommendation. Three services exist:
a Laravel API that owns the data, an Angular client, and a stateless FastAPI analyser.

The binding constraint is invariant I7: Laravel and FastAPI must agree on the
`/v1/analyze` contract, and that agreement is proven by a contract test in which **both
sides consume the same fixture**. A shared fixture is the crux — a test that invents its
own inline JSON proves nothing about the other side.

## Decision

One repository:

```
backend/     Laravel · frontend/   Angular · ai-service/  FastAPI
contracts/   OpenAPI schema + fixtures shared by both backends
infra/       compose + Dockerfiles · docs/  ADRs, PROGRESS
```

`contracts/` is the reason. It holds `ai-openapi.json` and the analyze fixtures that the
Pest suite and the pytest suite both read.

## Alternatives considered

- **Three repositories.** Rejected: the shared contract fixture would need a published
  package or a git submodule. Either turns "did both sides agree?" into a versioning
  question and lets the two suites drift onto different fixture versions while both
  report green — exactly the failure I7 exists to prevent.
- **Monorepo with a tooling layer (Nx/Turborepo).** Rejected for now: three services in
  three different languages share no JS build graph, so the tool would coordinate almost
  nothing. Revisit if the frontend splits into multiple packages.

## Consequences

**Positive.** One clone, one `docker compose up`. A contract change and both consumers
move in a single commit. File ownership for parallel workstreams maps cleanly onto top-level
directories, which is how work is split (`backend/` to one workstream, `frontend/` to another).

**Negative.** CI must path-filter, or every push runs all three suites. Repository size
grows with three dependency trees (mitigated by `.gitignore`). Directory-level access
control is not possible, unlike separate repositories.

**Follow-on.** Top-level directories are the unit of workstream file ownership; two workstreams are
never assigned the same directory in one phase.
