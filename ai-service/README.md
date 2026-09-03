# OmniHear — ai-service

The stateless analysis half of OmniHear (spec §2, §3.1). Laravel owns every
byte of product data; this service receives text, returns an analysis, and
keeps nothing (invariant I6 — see `docs/ARCHITECTURE.md` at the repo root for
how it fits between ingestion and broadcast).

## What it does

`POST /v1/analyze` (and `/v1/analyze/batch`, up to 50 items) run one text
through a four-stage pipeline:

```
language detection  ->  sentiment  ->  category  ->  keyword extraction
```

| stage | implementation | trained? |
|---|---|---|
| language | weighted orthography + function-word + morphology vote (`app/analyzers/language.py`) | no — hand-built rules |
| sentiment (production) | `Xenova/bert-base-multilingual-uncased-sentiment`, int8 ONNX, CPU | pre-trained, not fine-tuned |
| sentiment (fallback) | TR/EN valence lexicon with negation and intensifier rules | no |
| category | multinomial Naive Bayes, word uni+bigrams | **yes**, trained in-repo |
| keywords | RAKE (degree/frequency over stopword-delimited phrases) | no |

Every request is HMAC-SHA256-signed over the raw body (`app/security.py`) and
carries `X-Correlation-Id`, so a Laravel request, its queue job, and this
service's log line can all be found by the same id (spec §3.6) — see
"Logging" below.

**Honest accuracy numbers, including where it is bad, live in
[`MODEL_CARD.md`](./MODEL_CARD.md).** In short: the ONNX and lexicon backends
are *not* equivalent, the lexicon fallback is meaningfully weaker, and which
one is active is never silent — it's part of `model_version`, reported by
`GET /health`, and logged at start-up.

## The ONNX weights

Not vendored in the repository (168 MB). `scripts/fetch_sentiment_model.py`
downloads and SHA-256-verifies them from `Xenova/bert-base-multilingual-uncased-sentiment`
(Hugging Face) into `models/sentiment/` — run once, at image build time, never
at request time (ADR-0004: weights are a build artifact, so the running
service does no network I/O and stays stateless):

```bash
python -m scripts.fetch_sentiment_model
```

`SENTIMENT_BACKEND` controls what happens if they're absent:

| value | behaviour |
|---|---|
| `auto` (default, and what a fresh checkout / CI gets) | ONNX if the weights and the `onnx` extra are present, otherwise falls back to the lexicon backend with a start-up warning |
| `onnx` | requires ONNX; fails loudly on missing weights (what the container image pins, so a broken weights layer breaks the build instead of silently degrading quality) |
| `lexicon` | never touches ONNX |

On this checkout the weights are already present (confirmed via `GET /health`
below), so `auto` resolves to `onnx`.

## Run it

```bash
python -m venv .venv
.venv/Scripts/activate        # .venv/bin/activate on Linux/macOS
pip install -e ".[dev,onnx]"
cp .env.example .env          # AI_SERVICE_HMAC_SECRET must match the backend's
uvicorn app.main:app --reload --port 8001
```

Verified against a running instance on this checkout:

```
$ curl -s http://localhost:8001/health
{"status":"ok","service":"ai-service","model_version":"omnihear-onnx-f50df013ccc9","sentiment_backend":"onnx"}
```

In the dev stack (`infra/docker-compose.dev.yml`) this is the `ai-service`
container, port `8001`, built from `infra/docker/ai-service.Dockerfile`; see
the root `README.md` for bringing up the full stack.

## Tests

```bash
pip install -e ".[dev]"       # dev extra alone is enough — CI runs the suite
pytest -rs                    #   this way too, on the lexicon fallback
ruff check .
ruff format --check .
```

Run on this checkout:

```
$ ruff check .
All checks passed!

$ ruff format --check .
45 files already formatted

$ pytest -rs
274 passed, 1 warning in 5.93s
```

(The one warning is `httpx`/`starlette.testclient`'s own deprecation notice,
unrelated to this service's code.) `pytest.ini_options.pythonpath = ["."]`
means `scripts/` is importable from tests without an install step —
`tests/test_contract_fixtures.py` and `tests/test_openapi_contract.py` use
that to check the trained artifact and the exported OpenAPI schema aren't
stale.

Reproducing `MODEL_CARD.md`'s numbers yourself:

```bash
python -m scripts.evaluate_category_model            # held-out confusion matrices
python -m scripts.evaluate_category_model --dataset seed
python -m scripts.evaluate_sentiment                 # both sentiment backends
python -m pytest tests/test_latency.py -s             # p50 / p95
```

## Logging

`app/logging_config.py` renders one JSON object per line — `timestamp`,
`level`, `message`, `logger`, `correlation_id`, plus whatever else the call
site attaches through `extra=` (e.g. `duration_ms`, `category`, `label`) —
matching the shape of the backend's `json` Monolog channel
(`backend/config/logging.php`) so a single correlation id can be searched
across both services' logs for one request.

`app/routers/analyze.py`'s module docstring states the one rule that must
never be relaxed here: **request text is PII under KVKK (spec §8) and is
never logged** — only `correlation_id`, `duration_ms`, and the resulting
`label`/`category` are. `tests/test_logging_privacy.py` proves the negative
(the request text never reaches a captured log record, across the success,
rejection, and validation-failure paths) and `tests/test_logging_config.py`
proves the positive (a formatted record is valid JSON and carries the
correlation id).

## Contract

`POST /v1/analyze` / `/v1/analyze/batch` request and response shapes are
Pydantic v2 models in `app/schemas.py`, exported to
`contracts/ai-openapi.json` (`scripts/export_openapi.py`) and checked against
shared fixtures in `contracts/fixtures/analyze/` from both this service
(`tests/test_contract_fixtures.py`) and the backend (invariant I7) — see the
`ai-contract-sync` skill before changing either side.
