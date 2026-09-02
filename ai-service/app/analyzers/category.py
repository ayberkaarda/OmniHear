"""Multinomial Naive Bayes category classifier.

ADR-0004 requires the category classifier to be *trained in-repo* with a
deterministic artifact. This implementation deliberately uses no ML
framework:

* scikit-learn would add ~90 MB (with scipy) to an image whose target is
  under 1 GB, for a model that is 40 lines of arithmetic.
* A pure-Python multinomial NB has no float non-determinism from BLAS
  thread counts, so ``train`` -> ``artifact`` -> ``fingerprint`` is
  bit-reproducible across machines. That is what makes ``model_version``
  meaningful rather than decorative.
* On a few hundred labelled examples, NB is a genuinely reasonable
  choice: it is the estimator least prone to blowing up on sparse,
  small-sample text.

Features are word unigrams and bigrams over diacritic-folded tokens.
Stopwords are **kept**, because for this task the function words *are*
the signal — "please add", "lütfen ekleyin" and "would be nice" are what
separate a feature request from a complaint about the same subject.

The artifact (``app/models/category_model.json``) is produced by
``scripts/train_category_model.py`` and committed. This module only reads
it, at start-up.
"""

import hashlib
import json
import math
from dataclasses import dataclass
from pathlib import Path

from app.analyzers.text import fold_diacritics, split_phrases
from app.schemas import Category

ARTIFACT_FORMAT_VERSION = 1

DEFAULT_ARTIFACT_PATH = Path(__file__).resolve().parent.parent / "models" / "category_model.json"

# Highest confidence the classifier is allowed to report. Naive Bayes is
# structurally overconfident (it multiplies correlated evidence as if it
# were independent); claiming 0.999 on a hand-labelled seed set of a few
# hundred rows would be a lie.
_MAX_CONFIDENCE = 0.95

# Confidence reported when the text contains no feature the model has ever
# seen. The prediction then degenerates to the class prior, and the number
# says so.
_NO_EVIDENCE_CONFIDENCE = 0.2

# Order in which an exact tie between class priors is broken. See
# CategoryClassifier._resolve_fallback for why this is not left to chance.
_TIE_BREAK_ORDER = (
    Category.COMPLAINT,
    Category.PRAISE,
    Category.FEATURE_REQUEST,
    Category.BUG,
)


@dataclass(frozen=True)
class CategoryOutcome:
    category: Category
    confidence: float
    matched_features: int


def featurize(text: str) -> list[str]:
    """Word unigrams and bigrams, diacritic-folded, phrase-bounded.

    Bigrams do not cross a punctuation or digit boundary, so "slow. Add
    dark mode" never produces the spurious bigram "slow add".
    """
    features: list[str] = []
    for run in split_phrases(text):
        tokens = [fold_diacritics(token) for token in run]
        features.extend(tokens)
        features.extend(
            f"{first} {second}" for first, second in zip(tokens, tokens[1:], strict=False)
        )
    return features


class CategoryClassifier:
    """Reads a trained artifact and classifies text into a `Category`."""

    def __init__(self, artifact_path: Path = DEFAULT_ARTIFACT_PATH) -> None:
        raw = artifact_path.read_bytes()
        artifact = json.loads(raw.decode("utf-8"))

        if artifact.get("format_version") != ARTIFACT_FORMAT_VERSION:
            raise ValueError(
                f"{artifact_path}: unsupported format_version "
                f"{artifact.get('format_version')!r}, expected {ARTIFACT_FORMAT_VERSION}"
            )

        self._categories = [Category(name) for name in artifact["categories"]]
        self._log_prior: list[float] = artifact["log_prior"]
        self._weights: dict[str, list[float]] = artifact["log_likelihood"]
        self._unseen: list[float] = artifact["unseen_log_likelihood"]

        # Hash the artifact bytes verbatim: retraining on different seed
        # data changes this, and therefore changes model_version.
        self._fingerprint = hashlib.sha256(raw).hexdigest()

        self._fallback_category = self._resolve_fallback()

    def _resolve_fallback(self) -> Category:
        """Category used when the text contains no known feature.

        Normally the prior's arg-max. The seed set is balanced, so the
        priors tie exactly and the arg-max would be decided by list order
        — which is how contentless input ("12345", an emoji-only review)
        ended up labelled `bug`. That is not a neutral default: a false
        `bug` inflates the defect KPI the product exists to report, while
        a false `complaint` costs a human one glance in the inbox. So ties
        are broken explicitly, cheapest-error first.
        """
        best = max(self._log_prior)
        tied = [
            category
            for category, prior in zip(self._categories, self._log_prior, strict=True)
            if prior == best
        ]
        if len(tied) == 1:
            return tied[0]
        for category in _TIE_BREAK_ORDER:
            if category in tied:
                return category
        return tied[0]

    @property
    def fingerprint(self) -> str:
        return self._fingerprint

    @property
    def categories(self) -> list[Category]:
        return list(self._categories)

    def classify(self, text: str) -> CategoryOutcome:
        scores = list(self._log_prior)
        matched = 0

        for feature in featurize(text):
            weights = self._weights.get(feature)
            if weights is None:
                continue
            matched += 1
            for index, weight in enumerate(weights):
                scores[index] += weight

        if matched == 0:
            return CategoryOutcome(
                category=self._fallback_category,
                confidence=_NO_EVIDENCE_CONFIDENCE,
                matched_features=0,
            )

        best = max(range(len(scores)), key=scores.__getitem__)
        confidence = _posterior_confidence(scores)
        return CategoryOutcome(
            category=self._categories[best],
            confidence=confidence,
            matched_features=matched,
        )

    def unseen_log_likelihood(self) -> list[float]:
        """Exposed for the training/evaluation scripts and their tests."""
        return list(self._unseen)


def _posterior_confidence(scores: list[float]) -> float:
    """Softmax over the log-posteriors, capped at :data:`_MAX_CONFIDENCE`.

    Naive Bayes is famous for emitting near-1.0 posteriors, and a
    length-normalising temperature was the obvious defence. Measured on
    the held-out set it turned out to be the wrong move: plain softmax
    plus the cap is already well calibrated here, while dividing by the
    document length pushed the mean confidence down to 0.47 against a
    real accuracy of 0.85 — under-confident by almost as much as raw NB
    is normally over-confident.

    Reliability on data/category_eval.jsonl (see MODEL_CARD.md):

        confidence [0.0, 0.5) -> accuracy 0.44
        confidence [0.5, 0.7) -> accuracy 0.67
        confidence [0.7, 0.9) -> accuracy 0.79
        confidence [0.9, 1.0] -> accuracy 0.98

    That is close enough to the diagonal for a consumer to threshold on.
    The 80-row eval set is small, so these numbers carry wide error bars;
    they are a sanity check, not a guarantee.
    """
    ceiling = max(scores)
    exponentiated = [math.exp(score - ceiling) for score in scores]
    confidence = max(exponentiated) / sum(exponentiated)
    return round(min(_MAX_CONFIDENCE, confidence), 4)
