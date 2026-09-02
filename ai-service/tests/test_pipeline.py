"""The composed pipeline: protocol conformance, statelessness, versioning."""

from pathlib import Path

import pytest

from app.analyzers.base import SentimentAnalyzer
from app.analyzers.category import CategoryClassifier
from app.analyzers.pipeline import PipelineAnalyzer, build_sentiment_backend
from app.analyzers.registry import get_pipeline
from app.analyzers.sentiment import NEUTRAL_BAND, SentimentBackend, label_for
from app.analyzers.sentiment_lexicon import LexiconSentimentBackend
from app.analyzers.sentiment_onnx import (
    SentimentModelUnavailableError,
    missing_requirements,
)
from app.model_version import PIPELINE_SOURCE_FILES, build_model_version
from app.schemas import AnalysisResult, Category, SentimentLabel

PACKAGE_ROOT = Path(__file__).resolve().parent.parent / "app"


@pytest.fixture(scope="module")
def lexicon_pipeline() -> PipelineAnalyzer:
    """A pipeline pinned to the lexicon backend.

    Pinned rather than taken from the registry so these tests assert the
    same thing whether or not the ONNX weights happen to be present.
    """
    return PipelineAnalyzer(LexiconSentimentBackend(), CategoryClassifier())


# --- protocol conformance ----------------------------------------------------


def test_pipeline_satisfies_the_analyzer_protocol(lexicon_pipeline: PipelineAnalyzer) -> None:
    """The F1 seam must still hold: routers and schemas were not to change."""
    assert isinstance(lexicon_pipeline, SentimentAnalyzer)


def test_lexicon_backend_satisfies_the_backend_protocol() -> None:
    assert isinstance(LexiconSentimentBackend(), SentimentBackend)


def test_pipeline_returns_a_contract_valid_result(lexicon_pipeline: PipelineAnalyzer) -> None:
    result = lexicon_pipeline.analyze("Uygulama sürekli çöküyor, çok kötü.", "tr")
    assert isinstance(result, AnalysisResult)
    assert -1.0 <= result.sentiment_score <= 1.0
    assert 0.0 <= result.confidence <= 1.0
    assert isinstance(result.sentiment_label, SentimentLabel)
    assert isinstance(result.category, Category)
    assert len(result.keywords) <= 10
    assert len(result.language) == 2


# --- statelessness (invariant I6) --------------------------------------------


def test_repeated_calls_return_identical_results(lexicon_pipeline: PipelineAnalyzer) -> None:
    text = "The app crashes constantly and support never replies."
    assert lexicon_pipeline.analyze(text, None) == lexicon_pipeline.analyze(text, None)


def test_an_interleaved_call_does_not_change_the_answer(
    lexicon_pipeline: PipelineAnalyzer,
) -> None:
    """No accumulated corpus, no cache keyed on previous input: analysing
    something else in between must not shift the result."""
    text = "Please add a dark mode option."
    before = lexicon_pipeline.analyze(text, None)
    lexicon_pipeline.analyze("Completely unrelated: harika bir uygulama!", "tr")
    assert lexicon_pipeline.analyze(text, None) == before


def test_call_order_does_not_matter(lexicon_pipeline: PipelineAnalyzer) -> None:
    texts = ["harika", "The app crashes.", "Lütfen widget ekleyin.", "12345"]
    forward = [lexicon_pipeline.analyze(text, None) for text in texts]
    backward = list(reversed([lexicon_pipeline.analyze(text, None) for text in reversed(texts)]))
    assert forward == backward


# --- confidence composition --------------------------------------------------


def test_confidence_is_the_product_of_both_stages() -> None:
    """One number is exposed for two predictions, so a result is only as
    trustworthy as its weakest stage."""
    backend = LexiconSentimentBackend()
    classifier = CategoryClassifier()
    pipeline = PipelineAnalyzer(backend, classifier)

    text = "Uygulama sürekli çöküyor ve hiç açılmıyor."
    result = pipeline.analyze(text, "tr")
    expected = backend.score(text, "tr").confidence * classifier.classify(text).confidence
    assert result.confidence == pytest.approx(round(expected, 4))


# --- model_version -----------------------------------------------------------


def test_model_version_names_the_active_backend(lexicon_pipeline: PipelineAnalyzer) -> None:
    assert lexicon_pipeline.model_version.startswith("omnihear-lex-")


def test_model_version_is_stable_across_instances() -> None:
    first = PipelineAnalyzer(LexiconSentimentBackend(), CategoryClassifier())
    second = PipelineAnalyzer(LexiconSentimentBackend(), CategoryClassifier())
    assert first.model_version == second.model_version


def test_model_version_appears_in_every_result(lexicon_pipeline: PipelineAnalyzer) -> None:
    result = lexicon_pipeline.analyze("anything at all", None)
    assert result.model_version == lexicon_pipeline.model_version


@pytest.mark.parametrize(
    ("backend_id", "sentiment_fp", "category_fp"),
    [
        ("onnx", "aaa", "bbb"),
        ("lex", "aaa", "bbb"),
        ("lex", "ccc", "bbb"),
        ("lex", "aaa", "ddd"),
    ],
)
def test_model_version_changes_when_any_input_changes(
    backend_id: str, sentiment_fp: str, category_fp: str
) -> None:
    """ADR-0004 requires the reprocess query to be honest: every input
    that can change an answer must move the version."""
    baseline = build_model_version("lex", "aaa", "bbb")
    candidate = build_model_version(backend_id, sentiment_fp, category_fp)
    if (backend_id, sentiment_fp, category_fp) == ("lex", "aaa", "bbb"):
        assert candidate == baseline
    else:
        assert candidate != baseline


def test_every_declared_pipeline_source_file_exists() -> None:
    """A renamed module would silently drop out of the version hash."""
    for relative_path in PIPELINE_SOURCE_FILES:
        assert (PACKAGE_ROOT / relative_path).is_file(), relative_path


def test_model_version_can_be_pinned() -> None:
    pipeline = PipelineAnalyzer(
        LexiconSentimentBackend(),
        CategoryClassifier(),
        model_version_override="pinned-for-incident-replay",
    )
    assert pipeline.analyze("x", None).model_version == "pinned-for-incident-replay"


# --- backend selection -------------------------------------------------------


def test_lexicon_backend_is_selected_explicitly() -> None:
    backend = build_sentiment_backend("lexicon", Path("does/not/exist"))
    assert backend.backend_id == "lex"


def test_auto_falls_back_to_the_lexicon_when_weights_are_absent() -> None:
    backend = build_sentiment_backend("auto", Path("does/not/exist"))
    assert backend.backend_id == "lex"


def test_explicit_onnx_fails_loudly_when_weights_are_absent() -> None:
    """The container image pins "onnx" precisely so a missing weights
    layer breaks the deploy instead of degrading it silently."""
    with pytest.raises(SentimentModelUnavailableError):
        build_sentiment_backend("onnx", Path("does/not/exist"))


def test_an_unknown_backend_name_is_rejected() -> None:
    with pytest.raises(ValueError, match="SENTIMENT_BACKEND"):
        build_sentiment_backend("gpt-5", Path("does/not/exist"))


def test_missing_requirements_reports_why() -> None:
    reasons = missing_requirements(Path("does/not/exist"))
    assert reasons
    assert any("missing weights file" in reason for reason in reasons)


# --- registry ----------------------------------------------------------------


def test_registry_returns_one_shared_instance() -> None:
    """Weights are loaded once at start-up, not per request."""
    assert get_pipeline() is get_pipeline()


def test_registry_pipeline_reports_a_known_backend() -> None:
    assert get_pipeline().sentiment_backend_id in {"onnx", "lex"}


# --- shared sentiment helpers ------------------------------------------------


@pytest.mark.parametrize(
    ("score", "expected"),
    [
        (1.0, SentimentLabel.POSITIVE),
        (NEUTRAL_BAND + 0.01, SentimentLabel.POSITIVE),
        (NEUTRAL_BAND, SentimentLabel.NEUTRAL),
        (0.0, SentimentLabel.NEUTRAL),
        (-NEUTRAL_BAND, SentimentLabel.NEUTRAL),
        (-NEUTRAL_BAND - 0.01, SentimentLabel.NEGATIVE),
        (-1.0, SentimentLabel.NEGATIVE),
    ],
)
def test_label_thresholds(score: float, expected: SentimentLabel) -> None:
    assert label_for(score) is expected
