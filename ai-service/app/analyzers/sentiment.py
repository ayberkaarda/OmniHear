"""Shared contract for sentiment backends.

Two backends implement :class:`SentimentBackend`:

* :mod:`app.analyzers.sentiment_onnx` — the production path mandated by
  ADR-0004: a quantised multilingual transformer under ONNX Runtime.
  It requires model weights, which are a *build artifact* baked into the
  container image, and the optional ``onnx`` dependency extra.
* :mod:`app.analyzers.sentiment_lexicon` — a deterministic TR/EN lexicon
  scorer with no weights and no third-party dependencies.

The lexicon backend exists because the weights (168 MB) cannot live in
the repository, so a bare ``pip install -e ".[dev]" && pytest`` checkout —
which is exactly what CI does — has no transformer to run. It is a
genuinely weaker model and is **never silently swapped in**: the active
backend is part of ``model_version`` (``omnihear-lex-…`` vs
``omnihear-onnx-…``), is reported by ``GET /health``, and is logged once
at start-up. Every stored analysis therefore records which backend
produced it, which is what makes the ADR-0004 reprocess workflow able to
target exactly the rows that need redoing.
"""

from dataclasses import dataclass
from typing import Protocol, runtime_checkable

from app.schemas import SentimentLabel

# Scores inside +/- this band are reported as neutral. Chosen to match the
# band the F1 stub used, so that dashboards built against the stub do not
# shift their neutral bucket when the real pipeline lands.
NEUTRAL_BAND = 0.15


@dataclass(frozen=True)
class SentimentOutcome:
    """A backend's verdict on one text."""

    score: float
    label: SentimentLabel
    confidence: float


@runtime_checkable
class SentimentBackend(Protocol):
    """One interchangeable sentiment stage.

    Implementations must be side-effect free per call: all loading happens
    in ``__init__`` (start-up), never inside :meth:`score`.
    """

    @property
    def backend_id(self) -> str:
        """Short stable token that appears inside ``model_version``."""
        ...

    @property
    def fingerprint(self) -> str:
        """Hex digest of everything that makes this backend's output what it is.

        For a weights-based backend this is the digest of the weights; for
        a rule-based one it is the digest of the rules. It feeds the
        deterministic ``model_version`` hash.
        """
        ...

    def score(self, text: str, language: str) -> SentimentOutcome:
        """Return the sentiment verdict for `text` in detected `language`."""
        ...


def label_for(score: float) -> SentimentLabel:
    """Map a continuous score in [-1, 1] onto the three-way label."""
    if score > NEUTRAL_BAND:
        return SentimentLabel.POSITIVE
    if score < -NEUTRAL_BAND:
        return SentimentLabel.NEGATIVE
    return SentimentLabel.NEUTRAL
