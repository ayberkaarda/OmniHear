"""Category classifier: artifact integrity, behaviour, and held-out accuracy."""

import json
from pathlib import Path

import pytest

from app.analyzers.category import CategoryClassifier, featurize
from app.schemas import Category
from scripts.train_category_model import ARTIFACT_PATH, load_dataset, serialize, train

REPO_ROOT = Path(__file__).resolve().parent.parent
SEED_PATH = REPO_ROOT / "data" / "category_seed.jsonl"
EVAL_PATH = REPO_ROOT / "data" / "category_eval.jsonl"

# Floor for held-out accuracy. Set below the measured value (0.85 at the
# time of writing, see MODEL_CARD.md) so that ordinary retraining noise
# does not fail the build, but a real collapse does. ADR-0004 names
# overfitting this seed set as the phase's highest risk, which is exactly
# what this number is here to detect.
MINIMUM_HELD_OUT_ACCURACY = 0.75


@pytest.fixture(scope="module")
def classifier() -> CategoryClassifier:
    return CategoryClassifier()


# --- artifact integrity ------------------------------------------------------


def test_committed_artifact_matches_the_seed_data() -> None:
    """Editing data/category_seed.jsonl without retraining is a red test,
    not a silent drift between the corpus and the shipped model."""
    assert ARTIFACT_PATH.read_text(encoding="utf-8") == serialize(train(load_dataset(SEED_PATH)))


def test_training_is_reproducible() -> None:
    """Two runs over the same corpus must produce identical bytes —
    model_version is a hash of them."""
    rows = load_dataset(SEED_PATH)
    assert serialize(train(rows)) == serialize(train(rows))


def test_artifact_covers_every_contract_category(classifier: CategoryClassifier) -> None:
    assert set(classifier.categories) == set(Category)


def test_fingerprint_is_stable_across_instances() -> None:
    assert CategoryClassifier().fingerprint == CategoryClassifier().fingerprint


def test_fingerprint_changes_when_the_artifact_changes(tmp_path: Path) -> None:
    artifact = json.loads(ARTIFACT_PATH.read_text(encoding="utf-8"))
    artifact["log_prior"][0] -= 0.5
    altered = tmp_path / "category_model.json"
    altered.write_text(json.dumps(artifact), encoding="utf-8")

    assert CategoryClassifier(altered).fingerprint != CategoryClassifier().fingerprint


def test_unsupported_artifact_format_is_rejected(tmp_path: Path) -> None:
    artifact = json.loads(ARTIFACT_PATH.read_text(encoding="utf-8"))
    artifact["format_version"] = 999
    path = tmp_path / "category_model.json"
    path.write_text(json.dumps(artifact), encoding="utf-8")

    with pytest.raises(ValueError, match="format_version"):
        CategoryClassifier(path)


# --- datasets ----------------------------------------------------------------


def test_seed_and_eval_sets_share_no_text() -> None:
    """A held-out score means nothing if the sets overlap."""
    seed_texts = {row["text"] for row in load_dataset(SEED_PATH)}
    eval_texts = {row["text"] for row in load_dataset(EVAL_PATH)}
    assert not seed_texts & eval_texts


def test_both_datasets_cover_both_languages_and_all_categories() -> None:
    for path in (SEED_PATH, EVAL_PATH):
        rows = load_dataset(path)
        assert {row["language"] for row in rows} == {"tr", "en"}
        assert {row["category"] for row in rows} == {category.value for category in Category}


# --- featurisation -----------------------------------------------------------


def test_features_are_unigrams_and_bigrams() -> None:
    assert featurize("dark mode") == ["dark", "mode", "dark mode"]


def test_bigrams_do_not_cross_punctuation() -> None:
    assert "slow add" not in featurize("slow. Add caching")


def test_features_are_diacritic_folded() -> None:
    assert featurize("çöküyor") == featurize("cokuyor")


# --- behaviour ---------------------------------------------------------------


@pytest.mark.parametrize(
    ("text", "expected"),
    [
        ("The app crashes every time I open the camera.", Category.BUG),
        ("Uygulama sürekli çöküyor, hiçbir şey çalışmıyor.", Category.BUG),
        ("Please add a dark mode option.", Category.FEATURE_REQUEST),
        ("Karanlık mod eklerseniz çok sevinirim.", Category.FEATURE_REQUEST),
        ("Fantastic app, I use it every single day.", Category.PRAISE),
        ("Harika bir uygulama, her gün kullanıyorum.", Category.PRAISE),
        ("The monthly price is far too expensive.", Category.COMPLAINT),
        ("Aylık ücret çok pahalı, bu fiyata değmez.", Category.COMPLAINT),
    ],
)
def test_clear_cases_are_classified_correctly(
    classifier: CategoryClassifier, text: str, expected: Category
) -> None:
    assert classifier.classify(text).category is expected


def test_classification_is_deterministic(classifier: CategoryClassifier) -> None:
    text = "The app crashes and support never replied."
    assert classifier.classify(text) == classifier.classify(text)


@pytest.mark.parametrize("text", ["12345", "👍👍👍", "!!!", ""])
def test_contentless_text_falls_back_without_claiming_confidence(
    classifier: CategoryClassifier, text: str
) -> None:
    outcome = classifier.classify(text)
    assert outcome.matched_features == 0
    assert outcome.confidence <= 0.25


def test_contentless_text_does_not_default_to_bug(classifier: CategoryClassifier) -> None:
    """A false `bug` inflates the defect KPI the product exists to report;
    the tie-break is explicit for that reason."""
    assert classifier.classify("👍👍👍").category is not Category.BUG


def test_confidence_stays_inside_the_contract_bounds(
    classifier: CategoryClassifier,
) -> None:
    for row in load_dataset(EVAL_PATH):
        assert 0.0 <= classifier.classify(row["text"]).confidence <= 1.0


# --- held-out accuracy -------------------------------------------------------


def _accuracy(classifier: CategoryClassifier, rows: list[dict[str, str]]) -> float:
    correct = sum(
        classifier.classify(row["text"]).category.value == row["category"] for row in rows
    )
    return correct / len(rows)


def test_held_out_accuracy_meets_the_floor(classifier: CategoryClassifier) -> None:
    assert _accuracy(classifier, load_dataset(EVAL_PATH)) >= MINIMUM_HELD_OUT_ACCURACY


@pytest.mark.parametrize("language", ["tr", "en"])
def test_held_out_accuracy_meets_the_floor_in_each_language(
    classifier: CategoryClassifier, language: str
) -> None:
    """A model that works only in English would pass the combined check
    while being useless for a TR-market product."""
    rows = [row for row in load_dataset(EVAL_PATH) if row["language"] == language]
    assert _accuracy(classifier, rows) >= MINIMUM_HELD_OUT_ACCURACY
