# `/v1/analyze` contract fixtures

Shared scenarios for the Laravel <-> FastAPI analysis contract. **Both**
sides consume these files: the FastAPI side in
`ai-service/tests/test_contract_fixtures.py`, the Laravel side from F5
onwards. Per CONTRIBUTING.md §2, a test may not treat its own inline JSON as
proof for a shape these fixtures already cover.

The schema they conform to is `contracts/ai-openapi.json`, which is
generated from the Pydantic models by `ai-service/scripts/export_openapi.py`
and must never be hand-edited.

## File format

One file per scenario. Every file has the same top-level shape:

| key | meaning |
|---|---|
| `name` | matches the filename, without `.json` |
| `description` | what the scenario is for |
| `endpoint` | `/v1/analyze` or `/v1/analyze/batch` |
| `request` | the exact JSON request body |
| `signature` | how to build `X-Signature`: `valid` (HMAC-SHA256 of the serialised `request`), `tampered` (well-formed hex that does not match), `absent` |
| `correlation_id` | value for `X-Correlation-Id`, or `null` to omit the header |
| `status` | expected HTTP status |
| `response` | expected JSON response body |

`signature: "valid"` is computed over the request body **as the client
serialises it**. The HMAC covers raw bytes, so a client that re-serialises
with different key ordering or spacing computes a different signature —
sign the exact bytes you send.

## What is normative, and what is not

**Normative — a change here is a contract change:**

- the set of keys in `response`, at every level
- `status`
- `code` in every error response
- the enum membership of `sentiment_label` and `category`
- the bounds the schema declares (`sentiment_score` in [-1, 1],
  `confidence` in [0, 1], at most 10 `keywords`, `language` exactly two
  characters)

**Illustrative — do not assert equality on these:**

- `sentiment_score`, `confidence`, `keywords` and the specific
  `category` value. They come from a real model run and will move when
  the model is retrained; that is what `model_version` exists to record.
- `model_version` itself. These files were generated on the ONNX
  sentiment backend, so they read `omnihear-onnx-…`. A checkout without
  the model weights runs the lexicon fallback and answers
  `omnihear-lex-…`. Assert that it is a non-empty string, never that it
  equals a particular value.
- `message` in error responses. `VALIDATION_ERROR` carries a Pydantic
  rendering that changes with the library version. Only `code` is
  contractual.

## Scenarios

| file | covers |
|---|---|
| `single-tr-complaint` | Turkish negative complaint, explicit `tr` hint |
| `single-en-praise` | English positive praise |
| `single-bug-report` | bug report with **no** `language_hint` — detection must resolve it |
| `single-tr-feature-request` | Turkish feature request |
| `single-neutral-ambiguous` | text that lands inside the neutral band |
| `single-edge-emoji-only` | emoji-only body: no letters, `keywords` must be `[]` |
| `single-wrong-language-hint` | Turkish text hinted as `en`; detection wins, `language` is `tr` |
| `batch-fifty-items` | batch at the maximum size of 50 |
| `error-invalid-signature` | 401 `INVALID_SIGNATURE` |
| `error-validation-error` | 422 `VALIDATION_ERROR` (empty `text`) |
| `error-batch-too-large` | 422 `BATCH_TOO_LARGE` (51 items) |

## Regenerating

The 200-response bodies were captured from a live run of the service on
the ONNX backend. They are static files on purpose: a fixture that
regenerates itself cannot detect the drift it exists to catch. When a
deliberate contract change lands, update these by hand (or re-capture)
in the same commit as the schema change, and bump `model_version`'s
inputs so the change is visible in stored data.
