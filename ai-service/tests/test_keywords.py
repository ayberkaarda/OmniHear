"""Statistical keyword extraction (RAKE)."""

import pytest

from app.analyzers.keywords import MAX_KEYWORDS, extract


def test_extracts_content_phrases_from_turkish_text() -> None:
    keywords = extract("Uygulama sürekli çöküyor ve para iadesi istiyorum.")
    assert "uygulama sürekli çöküyor" in keywords
    assert "para iadesi istiyorum" in keywords


def test_extracts_content_phrases_from_english_text() -> None:
    keywords = extract("Please add a dark mode option to the settings screen.")
    assert "dark mode option" in keywords
    assert "settings screen" in keywords


def test_stopwords_never_appear_as_keywords() -> None:
    keywords = extract("The app is very good and I like it a lot")
    flattened = {word for phrase in keywords for word in phrase.split()}
    assert not flattened & {"the", "is", "very", "and", "i", "it", "a"}


def test_turkish_stopwords_are_stopped_without_diacritics_too() -> None:
    """Reviews are routinely typed without diacritics; "cok" must stop
    exactly like "çok"."""
    assert "cok" not in " ".join(extract("Uygulama cok guzel ve cok hizli"))
    assert "çok" not in " ".join(extract("Uygulama çok güzel ve çok hızlı"))


def test_diacritics_are_preserved_in_the_returned_keywords() -> None:
    """Folding is a lookup key, not a replacement — the UI shows these."""
    assert "sürekli çöküyor" in " ".join(extract("Uygulama sürekli çöküyor."))


def test_result_never_exceeds_the_contract_limit() -> None:
    text = " ".join(f"keyword{index}" for index in range(50))
    assert len(extract(text)) <= MAX_KEYWORDS


def test_limit_is_configurable_below_the_contract_maximum() -> None:
    text = "crashes constantly, terrible support, expensive price, missing features"
    assert len(extract(text, limit=2)) == 2


@pytest.mark.parametrize("text", ["", "   ", "12345", "👍👍", "!!!", "the and is"])
def test_texts_with_no_content_words_yield_no_keywords(text: str) -> None:
    assert extract(text) == []


def test_phrases_do_not_cross_punctuation_boundaries() -> None:
    """ "slow. Add" must never become one phrase."""
    assert "slow add" not in extract("Terribly slow. Add caching please.")


def test_phrases_do_not_cross_digit_boundaries() -> None:
    assert "version crashes" not in extract("version 14 crashes")


def test_phrase_length_is_capped() -> None:
    text = "completely broken unusable disastrous catastrophic release"
    assert all(len(phrase.split()) <= 3 for phrase in extract(text))


def test_single_characters_are_not_keywords() -> None:
    keywords = extract("a b c dark mode")
    assert all(len(word) > 1 for phrase in keywords for word in phrase.split())


def test_extraction_is_deterministic() -> None:
    text = "The app crashes constantly and the support team never replies."
    assert extract(text) == extract(text)


def test_ranking_prefers_longer_co_occurring_phrases() -> None:
    """RAKE scores a word by degree/frequency, so a word that lives inside
    a three-word phrase outranks one that merely repeats."""
    keywords = extract("Crashes. Crashes. Crashes. Completely unusable broken mess.")
    assert keywords[0] == "unusable broken mess"


def test_over_long_phrases_keep_their_tail() -> None:
    """Both languages put the head noun last ("dark mode *option*",
    "para iadesi *istiyorum*"), so truncation drops leading modifiers."""
    assert extract("completely unusable broken mess")[0] == "unusable broken mess"
