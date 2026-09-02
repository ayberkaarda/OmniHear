# ADR-0004 — Local inference pipeline for the AI service, not an LLM API

- **Status:** Accepted
- **Date:** 2026-09-02
- **Phase:** F1 (decision) / F3 (implementation)
- **Related spec:** §2 "AI Mikroservisi: Python 3.12 + FastAPI" · §3.1 stateless · §6.3 p95 < 800 ms · §8 KVKK

## Context

The spec freezes the AI service's stack but never states *how* sentiment and category
classification happen. `ai_analyses.model_version` exists and the spec says old analyses
must be reprocessable when the model changes, which implies the mechanism is versioned
and reproducible.

This is a portfolio project: no revenue, a public repository, and a reviewer who may well
clone it and run `docker compose up`.

## Decision

A local inference pipeline behind a `SentimentAnalyzer` protocol: language detection →
a small multilingual sentiment model (ONNX Runtime, int8, CPU) → a category classifier
trained in-repo → statistical keyword extraction.

F1 ships the protocol and a deterministic stub (`model_version: stub-0.1.0`). F3 replaces
the implementation without touching routers or schemas.

## Alternatives considered

- **LLM API call.** Rejected on four counts. Latency: structured JSON generation is
  typically 0.5–3 s against a p95 budget of 800 ms, with provider-side variance that
  makes the SLO test flaky. Demo: a clone without an API key cannot run the product, so
  the demo becomes a stub and reads as fake. Privacy: `feedbacks.body` is PII under KVKK;
  sending it to a third party requires a processing-inventory entry, a DPA and a privacy
  notice. Reproducibility: `model_version` would name a provider model that can change
  underneath us, so "reprocess everything analysed by the old model" stops being a
  well-defined operation.
- **Hybrid** (local sentiment, LLM category). Rejected: keeps every LLM drawback for the
  category path while doubling the code paths and the `model_version` axes.

## Consequences

**Positive.** Zero per-call cost; a clone runs with no credentials. Feedback text never
leaves the tenant's infrastructure, so the KVKK inventory stays one line. `model_version`
is a deterministic hash, so the reprocess workflow is real and demonstrable. Latency is
CPU-bound and predictable, leaving headroom under the 800 ms SLO. The service stays
stateless — weights are a build artifact, not runtime state, and are baked in at image
build so there is no network fetch at start-up.

**Negative.** F3 grows substantially: labelled seed data, a training script, ONNX export
and quantisation, a TR/EN evaluation set, and a latency regression test. The image grows
(target under 1 GB) and first `compose up` is slower.

**Highest risk.** The category classifier is likely to overfit a small seed set and
inflate "other" on real App Store reviews. Mitigation: publish the confusion matrix in
the README, grow the seed set from real feed samples, and let the `model_version` bump
plus reprocess command demonstrate the recovery path.

**Open slot.** The `SentimentAnalyzer` protocol leaves room for an optional LLM provider
later. That is deliberate and recorded here as "why not now" rather than "never".
