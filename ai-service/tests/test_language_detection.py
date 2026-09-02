"""Language-detection edge cases.

Spec §9 names these by name as the AI service's required test surface:
very short text, mixed TR/EN, emoji-only, numbers-only, and a wrong
`language_hint`. Each has its own section below.
"""

import pytest

from app.analyzers.language import DEFAULT_LANGUAGE, detect, normalize_hint

# --- ordinary text -----------------------------------------------------------


@pytest.mark.parametrize(
    "text",
    [
        "Uygulama sürekli çöküyor ve hiçbir şey çalışmıyor.",
        "Karanlık mod eklerseniz çok sevinirim, teşekkürler.",
        "Aylık ücret çok pahalı, bu fiyata kesinlikle değmez.",
        # No diacritics at all: the suffix and function-word signals must
        # carry it on their own.
        "Uygulama cok yavas calisiyor ve surekli donuyor.",
        "Lutfen karanlik mod ekleyin, bekliyoruz.",
    ],
)
def test_turkish_text_is_detected_as_turkish(text: str) -> None:
    assert detect(text).language == "tr"


@pytest.mark.parametrize(
    "text",
    [
        "The app crashes every time I open the camera.",
        "Please add a dark mode option in the next release.",
        "The monthly price is far too expensive for what you get.",
        # English words whose endings collide with Turkish suffixes
        # ("-lar", "-ler"): the collision guard must hold.
        "The seller is popular and the installer is smaller than before.",
        "A cooler dealer with a similar calendar would be great.",
    ],
)
def test_english_text_is_detected_as_english(text: str) -> None:
    assert detect(text).language == "en"


# --- very short text ---------------------------------------------------------


@pytest.mark.parametrize("text", ["iyi değil", "çok kötü", "harika"])
def test_short_turkish_text_is_detected(text: str) -> None:
    assert detect(text).language == "tr"


@pytest.mark.parametrize("text", ["not good", "the best", "it is fine"])
def test_short_english_text_is_detected(text: str) -> None:
    assert detect(text).language == "en"


def test_single_ambiguous_word_falls_back_to_the_hint() -> None:
    """ "ok" is not evidence for either language; the hint decides."""
    assert detect("ok", "tr").language == "tr"
    assert detect("ok", "en").language == "en"


def test_single_ambiguous_word_without_a_hint_uses_the_default() -> None:
    assert detect("ok").language == DEFAULT_LANGUAGE


# --- emoji-only and numbers-only ---------------------------------------------


@pytest.mark.parametrize("text", ["👍👍👍", "🎉", "😡😡", "★★★★★"])
def test_emoji_only_text_has_no_linguistic_evidence(text: str) -> None:
    detection = detect(text)
    assert detection.confidence == 0.0
    assert detection.tr_score == 0.0
    assert detection.en_score == 0.0
    assert detection.language == DEFAULT_LANGUAGE


def test_emoji_only_text_honours_the_hint() -> None:
    detection = detect("👍👍👍", "tr")
    assert detection.language == "tr"
    assert detection.used_hint is True


@pytest.mark.parametrize("text", ["12345", "1 2 3", "2024", "5/5", "-1.0"])
def test_numbers_only_text_has_no_linguistic_evidence(text: str) -> None:
    detection = detect(text)
    assert detection.confidence == 0.0
    assert detection.language == DEFAULT_LANGUAGE


@pytest.mark.parametrize("text", ["!!!", "...", "???", "   "])
def test_punctuation_and_whitespace_only_text_is_inconclusive(text: str) -> None:
    assert detect(text).confidence == 0.0


# --- mixed TR/EN -------------------------------------------------------------


def test_mixed_text_dominated_by_turkish_resolves_to_turkish() -> None:
    text = "Uygulama sürekli çöküyor, lütfen düzeltin. Thanks."
    assert detect(text).language == "tr"


def test_mixed_text_dominated_by_english_resolves_to_english() -> None:
    text = "The app crashes constantly and I cannot use it. Tesekkurler."
    assert detect(text).language == "en"


def test_mixed_text_always_returns_one_of_the_two_languages() -> None:
    """Whatever the mix, the result must remain a valid 2-letter code."""
    detection = detect("Dark mode lütfen add edin please çok isterim")
    assert detection.language in {"tr", "en"}
    assert len(detection.language) == 2


# --- wrong language_hint -----------------------------------------------------


def test_a_wrong_hint_loses_to_confident_detection() -> None:
    """Platform metadata routinely mislabels; detection wins when it has
    evidence (see app.analyzers.language's module docstring)."""
    detection = detect("Uygulama sürekli çöküyor, çok kötü bir deneyim.", "en")
    assert detection.language == "tr"
    assert detection.used_hint is False


def test_a_wrong_hint_loses_in_the_other_direction_too() -> None:
    detection = detect("The app crashes constantly, this is a terrible experience.", "tr")
    assert detection.language == "en"
    assert detection.used_hint is False


def test_a_hint_is_used_only_when_detection_is_inconclusive() -> None:
    detection = detect("👍", "de")
    assert detection.language == "de"
    assert detection.used_hint is True


# --- hint normalisation ------------------------------------------------------


@pytest.mark.parametrize(
    ("raw", "expected"),
    [
        ("tr", "tr"),
        ("TR", "tr"),
        ("tr-TR", "tr"),
        ("tr_TR", "tr"),
        ("en-GB", "en"),
        (" en ", "en"),
    ],
)
def test_well_formed_hints_are_normalised(raw: str, expected: str) -> None:
    assert normalize_hint(raw) == expected


@pytest.mark.parametrize("raw", [None, "", "x", "1", "12", "!!", "  "])
def test_malformed_hints_are_rejected_rather_than_guessed(raw: str | None) -> None:
    assert normalize_hint(raw) is None


def test_malformed_hint_does_not_leak_into_the_language_field() -> None:
    """A junk hint must never become the reported language: the contract
    declares exactly two characters."""
    detection = detect("👍", "not-a-language")
    assert len(detection.language) == 2
    assert detection.language == DEFAULT_LANGUAGE


# --- determinism -------------------------------------------------------------


def test_detection_is_deterministic() -> None:
    text = "Uygulama çok güzel ama biraz yavaş çalışıyor."
    first, second = detect(text), detect(text)
    assert first == second
