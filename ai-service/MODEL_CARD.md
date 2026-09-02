# OmniHear AI service — model card

What the analysis pipeline is, how well it works, and where it does not.
ADR-0004 requires the confusion matrix to be published "honestly,
including where it is bad"; that is what this file is for.

Every number here is reproducible:

```bash
cd ai-service
python -m scripts.evaluate_category_model            # held-out confusion matrices
python -m scripts.evaluate_category_model --dataset seed   # training-set accuracy
python -m scripts.evaluate_sentiment                 # both sentiment backends
python -m pytest tests/test_latency.py -s            # p50 / p95
```

---

## Pipeline

```
language detection  ->  sentiment  ->  category  ->  keyword extraction
```

| stage | implementation | trained? |
|---|---|---|
| language | weighted orthography + function-word + morphology vote (`analyzers/language.py`) | no — hand-built rules |
| sentiment (production) | `Xenova/bert-base-multilingual-uncased-sentiment`, int8 ONNX, CPU | pre-trained, not fine-tuned |
| sentiment (fallback) | TR/EN valence lexicon with negation and intensifier rules | no |
| category | multinomial Naive Bayes, word uni+bigrams | **yes**, in-repo |
| keywords | RAKE (degree/frequency over stopword-delimited phrases) | no |

### `model_version`

`omnihear-<backend>-<12 hex>`, e.g. `omnihear-onnx-f50df013ccc9`. The
digest covers the source of every pipeline module, the trained category
artifact, and the sentiment backend's fingerprint (the weights' SHA-256
for ONNX, the lexicon's digest for the fallback). Any change to any of
those moves the identifier, which is what makes
`SELECT … WHERE model_version <> :current` an honest reprocess list.

Current values on this tree:

| backend | `model_version` |
|---|---|
| ONNX | `omnihear-onnx-f50df013ccc9` |
| lexicon | `omnihear-lex-38b4e01eef0f` |

---

## The two sentiment backends are not equivalent

This is the most important caveat in this document.

The ONNX weights are 168 MB and cannot live in the repository, so
`pip install -e ".[dev]" && pytest` — exactly what CI runs — has no
transformer to load and falls back to the lexicon. The fallback is
**much** weaker. It is never selected silently: it changes
`model_version`, it is reported by `GET /health` as
`sentiment_backend`, and it logs a warning at start-up.

Measured on `data/category_eval.jsonl`, restricted to the 60 rows whose
polarity is unambiguous (`praise` -> positive, `bug`/`complaint` ->
negative; `feature_request` is excluded because its polarity is a
judgement call, not ground truth):

| backend | accuracy | abstained (said neutral) | wrong sign | precision when it committed |
|---|---|---|---|---|
| **ONNX** | **55/60 (91.7%)** | 2 | 3 | 94.8% |
| lexicon | 22/60 (36.7%) | 37 | 1 | 95.7% |

Per language:

| backend | Turkish | English |
|---|---|---|
| ONNX | 27/30 (90.0%) | 28/30 (93.3%) |
| lexicon | 13/30 (43.3%) | 9/30 (30.0%) |

**Read the abstention column before the accuracy column.** The lexicon is
not usually *wrong* — it got the sign wrong once in 60 — it is usually
*silent*. It abstains on 62% of rows because a valence lexicon has no way
to know that "The cart screen does not open, tapping it does nothing"
is a negative review: not one word in it carries polarity. It is a
high-precision, low-recall approximation, which makes it a defensible
development and CI stand-in and an unacceptable production model. Hence
the container image pins `SENTIMENT_BACKEND=onnx`, which raises rather
than degrades when the weights layer is missing.

### Why this model

`Xenova/distilbert-base-multilingual-cased-sentiments-student` was tried
first, since a 3-class sentiment model maps onto the contract most
directly. It was rejected on measurement: at **fp32**, not merely
quantised, it scored "The app crashes every time I open the camera.
Terrible." as `positive` 0.474 / `negative` 0.312. Comparing int8 against
fp32 confirmed quantisation was not the cause — the model itself is weak
on this domain.

The chosen model predicts a 1–5 star rating and was trained on product
reviews, which is exactly the input OmniHear ingests. The ordinal scale
also maps to the contract without inventing a calibration:
`score = (E[stars] - 3) / 2`, using the expectation over the full
distribution rather than the arg-max class.

`confidence` is the probability mass of the star classes belonging to the
reported label (1–2 negative, 3 neutral, 4–5 positive), so it answers
"how sure are you about *this* label" rather than "how peaked is the
distribution".

---

## Category classifier

Trained in-repo from `data/category_seed.jsonl` (240 hand-written rows,
30 per category per language) and evaluated on
`data/category_eval.jsonl` (80 rows, written independently; a test
asserts the two sets share no text).

### Held-out confusion matrices

Rows are truth, columns are prediction.

**Turkish — 34/40 (85.0%)**

| truth \ pred | bug | cmpl | feat | prai |
|---|---|---|---|---|
| bug | 7 | 2 | 0 | 1 |
| cmpl | 1 | 8 | 0 | 1 |
| feat | 1 | 0 | 9 | 0 |
| prai | 0 | 0 | 0 | 10 |

| class | precision | recall | f1 |
|---|---|---|---|
| bug | 0.78 | 0.70 | 0.74 |
| complaint | 0.80 | 0.80 | 0.80 |
| feature_request | 1.00 | 0.90 | 0.95 |
| praise | 0.83 | 1.00 | 0.91 |

**English — 33/40 (82.5%)**

| truth \ pred | bug | cmpl | feat | prai |
|---|---|---|---|---|
| bug | 8 | 1 | 0 | 1 |
| cmpl | 1 | 6 | 0 | 3 |
| feat | 0 | 0 | 10 | 0 |
| prai | 0 | 0 | 1 | 9 |

| class | precision | recall | f1 |
|---|---|---|---|
| bug | 0.89 | 0.80 | 0.84 |
| complaint | 0.86 | **0.60** | 0.71 |
| feature_request | 0.91 | 1.00 | 0.95 |
| praise | **0.69** | 0.90 | 0.78 |

**Combined — 67/80 (83.8%)**

| class | precision | recall | f1 |
|---|---|---|---|
| bug | 0.83 | 0.75 | 0.79 |
| complaint | 0.82 | 0.70 | 0.76 |
| feature_request | 0.95 | 0.95 | 0.95 |
| praise | 0.76 | 0.95 | 0.84 |

### Where it is bad

1. **English `complaint` recall is 0.60** — the worst number in the
   matrix. Four of the ten English complaints were labelled `praise`.
   The failure mode is visible in the seed data: complaints and praise
   share their vocabulary ("support", "price", "update", "interface")
   and differ mainly in polarity, which a bag-of-words model does not
   see. `praise` precision (0.69) is the same error viewed from the
   other side.
2. **`bug` vs `complaint` bleeds both ways** (3 + 2 confusions). The
   boundary is genuinely fuzzy: "the app drains my battery" is filed as
   a complaint here and as a bug by a reasonable person.
3. **`feature_request` is nearly solved (f1 0.95)** because its
   phrasing is formulaic — "please add", "lütfen ekleyin", "would be
   great", "olsa harika olurdu". That number should be read as "this
   class is easy", not as evidence the model is good.

### The overfitting gap is real

| dataset | accuracy |
|---|---|
| training (`category_seed.jsonl`, 240 rows) | 240/240 (**100.0%**) |
| held-out (`category_eval.jsonl`, 80 rows) | 67/80 (83.8%) |

A 16-point gap, with the training set memorised perfectly. This is
exactly the risk ADR-0004 flagged as highest, and it is not fixed — it is
measured. The recovery path is the one the ADR describes: grow the seed
set from real App Store samples during F4 ingestion, retrain, and let the
`model_version` bump plus a reprocess pass update existing rows.

Both eval sets are 40 rows per language. A single reclassified row moves
an accuracy figure by 2.5 points, so treat differences under ~5 points as
noise.

### Confidence calibration

The reported `confidence` is a plain softmax over the log-posteriors,
capped at 0.95. A length-normalising temperature was tried first (the
usual defence against Naive Bayes overconfidence) and measured worse: it
pushed mean confidence to 0.47 against a real accuracy of 0.85. Measured
reliability on the held-out set:

| reported confidence | actual accuracy | n |
|---|---|---|
| [0.0, 0.5) | 0.44 | 9 |
| [0.5, 0.7) | 0.67 | 9 |
| [0.7, 0.9) | 0.79 | 19 |
| [0.9, 1.0] | 0.98 | 43 |

Mean confidence 0.82 against accuracy 0.84. Close to the diagonal, on 80
rows — a sanity check, not a guarantee.

The `confidence` field in the API response is the **product** of the
sentiment and category confidences, since one number is exposed for two
independent predictions.

---

## Language detection

Discriminates Turkish from English. It does not identify arbitrary
languages: for anything else the caller's `language_hint` is the only
source of truth, and it is honoured whenever detection is inconclusive.
German text with no hint will be reported as `en`. This is a documented
limitation, not an oversight — the product ships tr/en only (spec §4).

A wrong `language_hint` **loses** to a confident detection. Hints arrive
from platform metadata (an App Store storefront locale), which routinely
mislabels an English review under a `tr` storefront.

Handled edge cases, each with a test in
`tests/test_language_detection.py`: very short text, mixed TR/EN,
emoji-only, digits-only, punctuation-only, a wrong hint, and malformed
hints (`"not-a-language"` is rejected outright rather than truncated to
Norwegian).

Two adjustments were made after measurement rather than by assumption:

- English agentive nouns ("seller", "cooler", "popular") collide with
  the Turkish suffixes `-ler`/`-lar`. A vowel-harmony check plus a short
  explicit collision list removes them.
- The Turkish progressive `-Iyor` is matched as an infix, not a suffix,
  because person endings follow it ("bekliyorum", "bekliyoruz").

---

## Keywords

RAKE, chosen over TF-IDF because a single feedback item is analysed in
isolation: there is no document collection at request time from which to
compute an IDF, and the service is stateless so it cannot accumulate one.

At most 10 phrases, at most 3 words each, ranked by summed
degree/frequency and tie-broken deterministically. Diacritics are
preserved in the output (they are shown in the UI); folding is only a
lookup key.

---

## Latency

Spec §6.3 sets p95 < 800 ms for `/v1/analyze`. Measured through the
endpoint (HMAC verification, parsing, full pipeline, serialisation) over
60 requests after 5 warm-ups, on the development machine:

| backend | p50 | p95 | max | batch of 50 |
|---|---|---|---|---|
| ONNX (int8, 1 intra-op thread) | 12.2 ms | **15.1 ms** | 15.4 ms | 520 ms (10.4 ms/item) |
| lexicon | 1.1 ms | **1.6 ms** | 3.1 ms | 10 ms (0.2 ms/item) |

The ONNX path sits ~50x under the budget, which is the headroom ADR-0004
predicted for CPU-bound local inference. `tests/test_latency.py` asserts
against the 800 ms SLO itself rather than a tightened local number: a
shared CI runner is slower than a workstation, and what the test needs to
catch is the order-of-magnitude regression (per-request model loading, a
network call on the request path), not a 3 ms drift.

Start-up cost, paid once at import by `app.analyzers.registry`, not per
request: **481 ms** to read the weights, SHA-256 the 168 MB artifact for
the fingerprint, and create the ORT session.

---

## Statelessness (invariant I6)

No disk writes, no network calls, and no shared mutable state at request
time. Weights are a build artifact fetched by
`scripts/fetch_sentiment_model.py` at image build and pinned by SHA-256.
`tests/test_pipeline.py` asserts the observable consequence: repeated
calls, interleaved calls and reversed call order all produce identical
results.

## Privacy (invariant I5 / KVKK)

`feedbacks.body` never reaches a log line. Only `correlation_id`,
duration, and the resulting label/category are logged.
`tests/test_logging_privacy.py` enforces this by capturing every record
emitted during a real request — including `extra=` attributes — and
searching it for the input text, on the success path, the 401 path and
the 422 path.

---

## Known limitations, collected

- The category classifier memorises its 240-row seed set (100% train,
  83.8% held-out).
- English `complaint` recall is 0.60; complaints leak into `praise`.
- The sentiment model is used off the shelf, not fine-tuned on Turkish
  app-store reviews.
- `feature_request` polarity is genuinely ambiguous and is excluded from
  the sentiment evaluation rather than scored against a guessed label.
- Language detection covers tr/en only.
- Text beyond 256 tokens is truncated for the sentiment stage (the
  contract allows 10 000 characters).
- The lexicon fallback abstains on ~62% of rows; CI therefore does not
  exercise the production sentiment quality at all. The ONNX tests skip
  when the weights are absent.
