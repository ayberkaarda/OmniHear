"""Sentiment backends.

The lexicon backend is tested unconditionally; it is what a checkout
without the model weights runs. The ONNX backend's tests skip when its
weights or its optional dependency extra are absent, which is the normal
state in CI — see MODEL_CARD.md for what that costs and how the ONNX path
is verified instead.
"""

import pytest

from app.analyzers.sentiment_lexicon import LexiconSentimentBackend
from app.analyzers.sentiment_onnx import OnnxSentimentBackend, missing_requirements
from app.config import settings
from app.schemas import SentimentLabel

ONNX_UNAVAILABLE = missing_requirements(settings.sentiment_model_dir)
requires_onnx = pytest.mark.skipif(
    bool(ONNX_UNAVAILABLE),
    reason=f"onnx backend unavailable: {'; '.join(ONNX_UNAVAILABLE)}",
)


@pytest.fixture(scope="module")
def lexicon() -> LexiconSentimentBackend:
    return LexiconSentimentBackend()


# --- lexicon backend ---------------------------------------------------------


@pytest.mark.parametrize(
    "text",
    [
        "Uygulama berbat, rezalet bir deneyim.",
        "Bu uygulama çok kötü ve sürekli çöküyor.",
        "This app is terrible and completely useless.",
        "Worst update ever, absolutely awful.",
    ],
)
def test_clearly_negative_text_scores_negative(lexicon: LexiconSentimentBackend, text: str) -> None:
    outcome = lexicon.score(text, "tr")
    assert outcome.label is SentimentLabel.NEGATIVE
    assert outcome.score < 0


@pytest.mark.parametrize(
    "text",
    [
        "Harika bir uygulama, mükemmel ve çok kullanışlı.",
        "Muhteşem, bayıldım, teşekkürler!",
        "Fantastic app, absolutely perfect and very useful.",
        "I love it, excellent work.",
    ],
)
def test_clearly_positive_text_scores_positive(lexicon: LexiconSentimentBackend, text: str) -> None:
    outcome = lexicon.score(text, "en")
    assert outcome.label is SentimentLabel.POSITIVE
    assert outcome.score > 0


def test_text_with_no_polarity_terms_is_neutral_and_says_so(
    lexicon: LexiconSentimentBackend,
) -> None:
    outcome = lexicon.score("The meeting is on Tuesday.", "en")
    assert outcome.label is SentimentLabel.NEUTRAL
    assert outcome.score == 0.0
    assert outcome.confidence < 0.5


def test_english_negation_flips_polarity(lexicon: LexiconSentimentBackend) -> None:
    assert lexicon.score("not good", "en").score < lexicon.score("good", "en").score


def test_turkish_postposed_negation_flips_polarity(lexicon: LexiconSentimentBackend) -> None:
    """Turkish negates after the term ("iyi değil"), which an
    English-shaped lookbehind window would miss entirely."""
    assert lexicon.score("iyi değil", "tr").score < lexicon.score("iyi", "tr").score


def test_turkish_yok_negation_is_handled(lexicon: LexiconSentimentBackend) -> None:
    assert lexicon.score("sorun yok", "tr").score > lexicon.score("sorun", "tr").score


def test_intensifiers_strengthen_and_diminishers_weaken(
    lexicon: LexiconSentimentBackend,
) -> None:
    plain = lexicon.score("yavaş", "tr").score
    assert lexicon.score("çok yavaş", "tr").score < plain
    assert lexicon.score("biraz yavaş", "tr").score > plain


def test_diacritic_free_turkish_scores_the_same_as_diacritic_turkish(
    lexicon: LexiconSentimentBackend,
) -> None:
    """Store reviews are routinely typed without diacritics."""
    assert lexicon.score("cok kotu", "tr").score == lexicon.score("çok kötü", "tr").score


def test_scores_stay_inside_the_contract_bounds(lexicon: LexiconSentimentBackend) -> None:
    text = " ".join(["berbat rezalet igrenc felaket"] * 40)
    outcome = lexicon.score(text, "tr")
    assert -1.0 <= outcome.score <= 1.0
    assert 0.0 <= outcome.confidence <= 1.0


def test_lexicon_is_deterministic(lexicon: LexiconSentimentBackend) -> None:
    assert lexicon.score("harika ama biraz yavaş", "tr") == lexicon.score(
        "harika ama biraz yavaş", "tr"
    )


def test_lexicon_fingerprint_is_stable() -> None:
    assert LexiconSentimentBackend().fingerprint == LexiconSentimentBackend().fingerprint


def test_lexicon_backend_id() -> None:
    assert LexiconSentimentBackend().backend_id == "lex"


# --- onnx backend ------------------------------------------------------------


@pytest.fixture(scope="module")
def onnx() -> OnnxSentimentBackend:
    return OnnxSentimentBackend(settings.sentiment_model_dir)


@requires_onnx
def test_onnx_backend_id(onnx: OnnxSentimentBackend) -> None:
    assert onnx.backend_id == "onnx"


@requires_onnx
@pytest.mark.parametrize(
    "text",
    [
        "Uygulama sürekli çöküyor, para iadesi istiyorum. Berbat.",
        "Ödeme yaptım ama premium açılmadı, destek cevap vermiyor.",
        "The app crashes every time I open the camera. Terrible.",
        "Worst update ever, I lost all my data.",
    ],
)
def test_onnx_scores_clearly_negative_text_negative(onnx: OnnxSentimentBackend, text: str) -> None:
    assert onnx.score(text, "tr").label is SentimentLabel.NEGATIVE


@requires_onnx
@pytest.mark.parametrize(
    "text",
    [
        "Harika bir uygulama, çok beğendim teşekkürler!",
        "Absolutely love this app, best purchase ever!",
    ],
)
def test_onnx_scores_clearly_positive_text_positive(onnx: OnnxSentimentBackend, text: str) -> None:
    assert onnx.score(text, "en").label is SentimentLabel.POSITIVE


@requires_onnx
def test_onnx_scores_stay_inside_the_contract_bounds(onnx: OnnxSentimentBackend) -> None:
    for text in ("harika", "berbat", "x" * 5000, "👍", "12345"):
        outcome = onnx.score(text, "tr")
        assert -1.0 <= outcome.score <= 1.0
        assert 0.0 <= outcome.confidence <= 1.0


@requires_onnx
def test_onnx_is_deterministic(onnx: OnnxSentimentBackend) -> None:
    text = "Uygulama güzel ama biraz yavaş çalışıyor."
    assert onnx.score(text, "tr") == onnx.score(text, "tr")


@requires_onnx
def test_onnx_handles_text_longer_than_the_sequence_limit(
    onnx: OnnxSentimentBackend,
) -> None:
    """10 000 characters is the contract maximum; truncation must not raise."""
    assert onnx.score("Bu uygulama berbat. " * 500, "tr").label is SentimentLabel.NEGATIVE


@requires_onnx
def test_onnx_fingerprint_covers_the_weights(onnx: OnnxSentimentBackend) -> None:
    assert len(onnx.fingerprint) == 64
    assert onnx.fingerprint == OnnxSentimentBackend(settings.sentiment_model_dir).fingerprint
