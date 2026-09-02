"""The real analysis pipeline (ADR-0004 / F3).

Composition, in order:

    language detection -> sentiment -> category -> keyword extraction

Each stage lives in its own module and is independently testable; this
class only wires them together and assembles the contract object. It
implements :class:`app.analyzers.base.SentimentAnalyzer` without changing
that Protocol, so routers and schemas are untouched — the swap happens at
``app.routers.analyze.get_analyzer``.

Statelessness (invariant I6) is structural, not a convention:

* Every model, lexicon and tokenizer is loaded in ``__init__``, which runs
  once at process start-up.
* ``analyze`` reads only its arguments and immutable instance attributes,
  and writes nothing — no disk, no network, no counters, no cache.

Two calls with the same input therefore return equal results in any
order, from any thread, in any worker process.

**Confidence** is the product of the sentiment and category confidences,
not either one alone. The contract exposes a single ``confidence`` for a
result that carries two independent predictions, and a consumer that
filters on it ("show me only what the model is sure about") is asking
about the whole row. Multiplying is the honest reading: a result is only
as trustworthy as its weakest stage.
"""

import logging
from pathlib import Path

from app.analyzers import keywords, language
from app.analyzers.category import CategoryClassifier
from app.analyzers.sentiment import SentimentBackend
from app.analyzers.sentiment_lexicon import LexiconSentimentBackend
from app.analyzers.sentiment_onnx import (
    OnnxSentimentBackend,
    SentimentModelUnavailableError,
    missing_requirements,
)
from app.model_version import build_model_version
from app.schemas import AnalysisResult

logger = logging.getLogger("ai_service.pipeline")

# Backend selection values accepted by SENTIMENT_BACKEND.
BACKEND_AUTO = "auto"
BACKEND_ONNX = "onnx"
BACKEND_LEXICON = "lexicon"


class PipelineAnalyzer:
    """Local inference pipeline implementing the SentimentAnalyzer protocol."""

    def __init__(
        self,
        sentiment_backend: SentimentBackend,
        classifier: CategoryClassifier,
        model_version_override: str | None = None,
    ) -> None:
        self._sentiment = sentiment_backend
        self._classifier = classifier
        self._model_version = model_version_override or build_model_version(
            backend_id=sentiment_backend.backend_id,
            sentiment_fingerprint=sentiment_backend.fingerprint,
            category_fingerprint=classifier.fingerprint,
        )

    @property
    def model_version(self) -> str:
        return self._model_version

    @property
    def sentiment_backend_id(self) -> str:
        return self._sentiment.backend_id

    def analyze(self, text: str, language_hint: str | None) -> AnalysisResult:
        detection = language.detect(text, language_hint)
        sentiment = self._sentiment.score(text, detection.language)
        category = self._classifier.classify(text)

        return AnalysisResult(
            sentiment_score=sentiment.score,
            sentiment_label=sentiment.label,
            category=category.category,
            confidence=round(sentiment.confidence * category.confidence, 4),
            keywords=keywords.extract(text),
            language=detection.language,
            model_version=self._model_version,
        )


def build_sentiment_backend(
    requested: str, model_dir: Path, intra_op_threads: int = 1
) -> SentimentBackend:
    """Resolve SENTIMENT_BACKEND into a concrete backend.

    ``onnx`` raises if the weights or the optional dependency extra are
    absent — that is the setting the container image uses, and a silently
    degraded production image is exactly what must not happen. ``auto``
    prefers ONNX and falls back to the lexicon with a warning, which is
    what a bare ``pip install -e ".[dev]"`` checkout (and therefore CI)
    gets. ``lexicon`` never touches ONNX.
    """
    normalized = requested.strip().lower()

    if normalized == BACKEND_LEXICON:
        return LexiconSentimentBackend()

    if normalized == BACKEND_ONNX:
        return OnnxSentimentBackend(model_dir, intra_op_threads=intra_op_threads)

    if normalized != BACKEND_AUTO:
        raise ValueError(
            f"SENTIMENT_BACKEND must be one of "
            f"{BACKEND_AUTO!r}, {BACKEND_ONNX!r}, {BACKEND_LEXICON!r}; got {requested!r}"
        )

    reasons = missing_requirements(model_dir)
    if reasons:
        logger.warning(
            "onnx sentiment backend unavailable, falling back to the lexicon backend; "
            "model_version will carry the 'lex' marker. reasons=%s",
            "; ".join(reasons),
        )
        return LexiconSentimentBackend()

    try:
        return OnnxSentimentBackend(model_dir, intra_op_threads=intra_op_threads)
    except SentimentModelUnavailableError as exc:
        logger.warning(
            "onnx sentiment backend failed to load, falling back to the lexicon backend; "
            "model_version will carry the 'lex' marker. reason=%s",
            exc,
        )
        return LexiconSentimentBackend()
