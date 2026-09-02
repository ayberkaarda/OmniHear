"""Deterministic stub analyzer — a test fake, not a production path.

F1 wired this as the default analyzer. F3 replaced it with
app.analyzers.pipeline.PipelineAnalyzer (ADR-0004); the stub is kept
because the test suite uses it as a cheap, fully deterministic fake when
exercising the routing and error-handling layers.

Produces contract-valid AnalysisResult values derived from a SHA-256 hash
of the input text. No randomness: identical input always yields identical
output. Do not add real inference logic here — the pipeline is the place
for that, and the stub's value is precisely that it has none.
"""

import hashlib
import re

from app.schemas import AnalysisResult, Category, SentimentLabel

# Fixed, not read from settings: the stub is a test fake, and a fake that
# reports the production model_version would let a misconfigured deployment
# masquerade as the real pipeline in stored data.
STUB_MODEL_VERSION = "stub-0.1.0"

_CATEGORIES = (
    Category.COMPLAINT,
    Category.PRAISE,
    Category.BUG,
    Category.FEATURE_REQUEST,
)

_WORD_RE = re.compile(r"[^\W\d_]+")

_MAX_KEYWORDS = 10
_NEUTRAL_BAND = 0.15


class StubAnalyzer:
    """Deterministic, non-ML analyzer used until a real engine lands (F3)."""

    def analyze(self, text: str, language_hint: str | None) -> AnalysisResult:
        digest = hashlib.sha256(text.encode("utf-8")).digest()

        # sentiment_score in [-1.0, 1.0], derived from the first two digest bytes.
        raw_score = int.from_bytes(digest[0:2], "big")  # 0..65535
        sentiment_score = round((raw_score / 65535) * 2 - 1, 4)

        if sentiment_score > _NEUTRAL_BAND:
            sentiment_label = SentimentLabel.POSITIVE
        elif sentiment_score < -_NEUTRAL_BAND:
            sentiment_label = SentimentLabel.NEGATIVE
        else:
            sentiment_label = SentimentLabel.NEUTRAL

        category = _CATEGORIES[digest[2] % len(_CATEGORIES)]
        confidence = round(digest[3] / 255, 4)

        keywords = self._extract_keywords(text)
        language = self._resolve_language(language_hint)

        return AnalysisResult(
            sentiment_score=sentiment_score,
            sentiment_label=sentiment_label,
            category=category,
            confidence=confidence,
            keywords=keywords,
            language=language,
            model_version=STUB_MODEL_VERSION,
        )

    @staticmethod
    def _extract_keywords(text: str) -> list[str]:
        seen: set[str] = set()
        unique_words: list[str] = []
        for word in _WORD_RE.findall(text.lower()):
            if word in seen:
                continue
            seen.add(word)
            unique_words.append(word)
            if len(unique_words) == _MAX_KEYWORDS:
                break
        return unique_words

    @staticmethod
    def _resolve_language(language_hint: str | None) -> str:
        # Real language detection lands in F3. For now: trust a well-formed
        # hint, otherwise fall back to "en" as specified in the contract.
        if language_hint and len(language_hint) == 2 and language_hint.isalpha():
            return language_hint.lower()
        return "en"
