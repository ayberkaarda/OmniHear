"""Analyzer interface contract.

Defines the seam between the API layer (routers/analyze.py) and the
sentiment/category analysis engine. F1 ships only a deterministic stub
implementation (see stub.py). A real ML- or LLM-backed engine is expected
to implement this same Protocol in a later phase (F3) without requiring
any change to routers or schemas — it is injected via FastAPI's
`Depends`, which is also what lets tests swap in a fake analyzer.
"""

from typing import Protocol, runtime_checkable

from app.schemas import AnalysisResult


@runtime_checkable
class SentimentAnalyzer(Protocol):
    """Contract every analysis engine (stub or real) must satisfy.

    Implementations MUST be side-effect free: no disk I/O, no network
    calls, no shared mutable state between calls. The service as a whole
    is stateless (per the project architecture contract), and this
    interface preserves that property so any implementation can be
    swapped in without behavioral surprises.
    """

    def analyze(self, text: str, language_hint: str | None) -> AnalysisResult:
        """Analyze `text` and return a fully contract-valid AnalysisResult.

        Args:
            text: Raw input text, 1..10000 characters (already validated
                by the request schema before this is called).
            language_hint: Optional ISO 639-1 language code hint supplied
                by the caller. Implementations MAY ignore it and perform
                their own detection, or use it directly.

        Returns:
            An AnalysisResult whose fields already satisfy every
            constraint declared in app.schemas (score bounds, enum
            membership, keyword count, language code shape, etc.) —
            callers do not re-validate; Pydantic enforces it on
            construction.
        """
        ...
