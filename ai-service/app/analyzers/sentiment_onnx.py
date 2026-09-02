"""ONNX Runtime sentiment backend — the production path of ADR-0004.

Model: ``Xenova/bert-base-multilingual-uncased-sentiment``, the ONNX
export of ``nlptown/bert-base-multilingual-uncased-sentiment``. It is a
multilingual BERT fine-tuned to predict a **1–5 star rating** from a
product review. Two properties made it the pick over a generic
three-class sentiment model:

* Its training domain is product reviews, which is precisely what
  OmniHear ingests (App Store, Google Play, Trustpilot).
* A star rating is an ordinal scale, so it maps onto the contract's
  continuous ``sentiment_score`` in [-1, 1] without inventing a
  calibration: ``score = (E[stars] - 3) / 2``. The expectation over the
  full distribution, rather than the arg-max class, is what makes a
  "mostly 4 with some 5" review land above a "4 or 2, hard to say" one.

Weights are a **build artifact**. ``scripts/fetch_sentiment_model.py``
downloads and checksum-verifies them at image build time; nothing here
touches the network. The service reads them once, at construction, and
never writes to disk — the stateless invariant (I6) holds.

Requires the optional ``onnx`` dependency extra (onnxruntime, tokenizers,
numpy). The imports are deliberately local to ``__init__`` so that a
checkout without the extra can still import this module and report the
backend as unavailable rather than exploding at start-up.
"""

import hashlib
from pathlib import Path
from typing import Final

from app.analyzers.sentiment import SentimentOutcome, label_for
from app.schemas import SentimentLabel

BACKEND_ID: Final = "onnx"

MODEL_FILENAME: Final = "model_int8.onnx"
TOKENIZER_FILENAME: Final = "tokenizer.json"
CONFIG_FILENAME: Final = "config.json"

REQUIRED_FILES: Final = (MODEL_FILENAME, TOKENIZER_FILENAME, CONFIG_FILENAME)

# BERT's positional embeddings stop at 512; 256 covers the overwhelming
# majority of store reviews while halving the worst-case latency. Longer
# text is truncated, which is the standard treatment and is documented in
# MODEL_CARD.md.
MAX_SEQUENCE_LENGTH: Final = 256

# Star index -> contract label bucket. Index 0 is "1 star".
_NEGATIVE_STARS: Final = (0, 1)
_NEUTRAL_STARS: Final = (2,)
_POSITIVE_STARS: Final = (3, 4)


class SentimentModelUnavailableError(RuntimeError):
    """Raised when the ONNX backend is explicitly requested but unusable."""


def missing_requirements(model_dir: Path) -> list[str]:
    """Return human-readable reasons this backend cannot run, or []."""
    reasons: list[str] = []

    try:
        import numpy  # noqa: F401
        import onnxruntime  # noqa: F401
        from tokenizers import Tokenizer  # noqa: F401
    except ImportError as exc:
        reasons.append(f"optional 'onnx' dependency extra is not installed ({exc.name})")

    for filename in REQUIRED_FILES:
        if not (model_dir / filename).is_file():
            reasons.append(f"missing weights file: {model_dir / filename}")

    return reasons


class OnnxSentimentBackend:
    """Quantised multilingual transformer sentiment scorer (CPU)."""

    def __init__(self, model_dir: Path, intra_op_threads: int = 1) -> None:
        reasons = missing_requirements(model_dir)
        if reasons:
            raise SentimentModelUnavailableError("; ".join(reasons))

        import json

        import numpy
        import onnxruntime
        from tokenizers import Tokenizer

        self._np = numpy
        self._model_dir = model_dir

        with (model_dir / CONFIG_FILENAME).open(encoding="utf-8") as handle:
            config = json.load(handle)
        self._num_labels = len(config["id2label"])
        if self._num_labels != 5:
            raise SentimentModelUnavailableError(
                f"expected a 5-class star-rating model, got {self._num_labels} labels"
            )

        self._tokenizer = Tokenizer.from_file(str(model_dir / TOKENIZER_FILENAME))
        self._tokenizer.enable_truncation(max_length=MAX_SEQUENCE_LENGTH)

        # One thread by default: uvicorn already runs requests concurrently,
        # and letting ORT fan out over every core turns p95 into a function
        # of how many requests happen to overlap.
        options = onnxruntime.SessionOptions()
        options.intra_op_num_threads = intra_op_threads
        options.inter_op_num_threads = 1
        self._session = onnxruntime.InferenceSession(
            str(model_dir / MODEL_FILENAME),
            options,
            providers=["CPUExecutionProvider"],
        )
        self._input_names = {tensor.name for tensor in self._session.get_inputs()}
        self._fingerprint = _digest_files(model_dir)

    @property
    def backend_id(self) -> str:
        return BACKEND_ID

    @property
    def fingerprint(self) -> str:
        return self._fingerprint

    @property
    def model_dir(self) -> Path:
        return self._model_dir

    def score(self, text: str, language: str) -> SentimentOutcome:
        """Score `text`. `language` is unused — the model is multilingual
        and applying a language gate would only add a failure mode."""
        np = self._np

        encoding = self._tokenizer.encode(text)
        input_ids = np.asarray([encoding.ids], dtype=np.int64)
        attention_mask = np.asarray([encoding.attention_mask], dtype=np.int64)

        feed = {"input_ids": input_ids, "attention_mask": attention_mask}
        if "token_type_ids" in self._input_names:
            feed["token_type_ids"] = np.zeros_like(input_ids)

        logits = self._session.run(None, feed)[0][0]
        probabilities = _softmax(np, logits)

        stars = float((probabilities * np.arange(1, self._num_labels + 1)).sum())
        score = round(max(-1.0, min(1.0, (stars - 3.0) / 2.0)), 4)
        label = label_for(score)
        confidence = round(float(_bucket_mass(probabilities, label)), 4)

        return SentimentOutcome(score=score, label=label, confidence=confidence)


def _softmax(np, logits):  # noqa: ANN001 - numpy is an optional import
    shifted = logits - logits.max()
    exponentiated = np.exp(shifted)
    return exponentiated / exponentiated.sum()


def _bucket_mass(probabilities, label: SentimentLabel) -> float:
    """Probability mass of the star classes that map to `label`.

    Reported as ``confidence`` so the number answers the question the
    consumer actually asks — "how sure are you about *this* label" — and
    not "how peaked is the star distribution".
    """
    if label is SentimentLabel.NEGATIVE:
        indices = _NEGATIVE_STARS
    elif label is SentimentLabel.POSITIVE:
        indices = _POSITIVE_STARS
    else:
        indices = _NEUTRAL_STARS
    return sum(float(probabilities[index]) for index in indices)


def _digest_files(model_dir: Path) -> str:
    """SHA-256 over every weights file, so ``model_version`` moves whenever
    the artifact does. Runs once at start-up (~0.3 s for 168 MB)."""
    digest = hashlib.sha256()
    for filename in REQUIRED_FILES:
        digest.update(filename.encode("utf-8"))
        with (model_dir / filename).open("rb") as handle:
            for chunk in iter(lambda: handle.read(1024 * 1024), b""):
                digest.update(chunk)
    return digest.hexdigest()
