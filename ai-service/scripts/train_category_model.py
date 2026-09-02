"""Train the category classifier and write its deterministic artifact.

    python -m scripts.train_category_model            # writes app/models/category_model.json
    python -m scripts.train_category_model --check    # verifies the committed artifact matches

Multinomial Naive Bayes with Laplace smoothing over word unigrams and
bigrams (see :mod:`app.analyzers.category` for why this estimator and no
framework).

**Determinism is the point.** The artifact is committed, and
``model_version`` is a hash of it, so a rebuild on a different machine
must produce byte-identical output. Three things guarantee that:

* Features are accumulated in sorted order, never in dict-iteration or
  set order.
* Every stored probability is rounded to a fixed number of decimals, so
  platform differences in the last float bit cannot leak into the file.
* ``json.dump`` runs with ``sort_keys=True`` and a fixed separator.

``--check`` is what the test suite calls: it retrains in memory and
fails if the committed artifact differs, which makes "someone edited the
seed data and forgot to retrain" a red test rather than a silent drift.
"""

from __future__ import annotations

import argparse
import json
import math
import sys
from collections import Counter
from pathlib import Path

# Allow `python scripts/train_category_model.py` as well as `-m`.
sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from app.analyzers.category import ARTIFACT_FORMAT_VERSION, featurize  # noqa: E402
from app.schemas import Category  # noqa: E402

REPO_ROOT = Path(__file__).resolve().parent.parent
SEED_PATH = REPO_ROOT / "data" / "category_seed.jsonl"
ARTIFACT_PATH = REPO_ROOT / "app" / "models" / "category_model.json"

# Laplace smoothing. Below 1.0 because the vocabulary is large relative to
# the corpus, and a full add-one washes out the signal from rare but very
# diagnostic bigrams such as "lutfen ekleyin".
ALPHA = 0.4

# A bigram must appear in at least this many documents to enter the model.
# Unigrams are kept at 1: with ~30 examples per class per language, most
# informative words appear exactly once.
MIN_UNIGRAM_DOCUMENT_FREQUENCY = 1
MIN_BIGRAM_DOCUMENT_FREQUENCY = 2

# Decimal places for every stored log-probability (see module docstring).
ROUNDING = 6


def load_dataset(path: Path) -> list[dict[str, str]]:
    """Read a JSONL dataset, validating the label set as it goes."""
    rows: list[dict[str, str]] = []
    valid_categories = {category.value for category in Category}

    with path.open(encoding="utf-8") as handle:
        for line_number, line in enumerate(handle, start=1):
            line = line.strip()
            if not line:
                continue
            row = json.loads(line)
            if row["category"] not in valid_categories:
                raise ValueError(f"{path}:{line_number}: unknown category {row['category']!r}")
            rows.append(row)

    if not rows:
        raise ValueError(f"{path}: dataset is empty")
    return rows


def select_vocabulary(rows: list[dict[str, str]]) -> list[str]:
    """Features passing their minimum document-frequency threshold, sorted."""
    document_frequency: Counter[str] = Counter()
    for row in rows:
        document_frequency.update(set(featurize(row["text"])))

    selected = [
        feature
        for feature, count in document_frequency.items()
        if count
        >= (MIN_BIGRAM_DOCUMENT_FREQUENCY if " " in feature else MIN_UNIGRAM_DOCUMENT_FREQUENCY)
    ]
    return sorted(selected)


def train(rows: list[dict[str, str]]) -> dict:
    """Fit multinomial NB and return the JSON-serialisable artifact."""
    categories = sorted(category.value for category in Category)
    category_index = {name: index for index, name in enumerate(categories)}

    vocabulary = select_vocabulary(rows)
    vocabulary_index = {feature: index for index, feature in enumerate(vocabulary)}

    document_counts = [0] * len(categories)
    # counts[feature_index][category_index]
    counts = [[0] * len(categories) for _ in vocabulary]
    totals = [0] * len(categories)

    for row in rows:
        column = category_index[row["category"]]
        document_counts[column] += 1
        for feature in featurize(row["text"]):
            index = vocabulary_index.get(feature)
            if index is None:
                continue
            counts[index][column] += 1
            totals[column] += 1

    total_documents = sum(document_counts)
    log_prior = [round(math.log(count / total_documents), ROUNDING) for count in document_counts]

    denominators = [totals[column] + ALPHA * len(vocabulary) for column in range(len(categories))]

    log_likelihood: dict[str, list[float]] = {}
    for index, feature in enumerate(vocabulary):
        log_likelihood[feature] = [
            round(math.log((counts[index][column] + ALPHA) / denominators[column]), ROUNDING)
            for column in range(len(categories))
        ]

    unseen_log_likelihood = [
        round(math.log(ALPHA / denominators[column]), ROUNDING) for column in range(len(categories))
    ]

    return {
        "format_version": ARTIFACT_FORMAT_VERSION,
        "algorithm": "multinomial_naive_bayes",
        "alpha": ALPHA,
        "categories": categories,
        "document_counts": document_counts,
        "log_prior": log_prior,
        "log_likelihood": log_likelihood,
        "unseen_log_likelihood": unseen_log_likelihood,
        "vocabulary_size": len(vocabulary),
    }


def serialize(artifact: dict) -> str:
    """Canonical JSON form. The trailing newline keeps the file POSIX-clean."""
    return json.dumps(artifact, ensure_ascii=False, indent=2, sort_keys=True) + "\n"


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument(
        "--check",
        action="store_true",
        help="verify the committed artifact matches a fresh training run",
    )
    arguments = parser.parse_args()

    rows = load_dataset(SEED_PATH)
    artifact = train(rows)
    payload = serialize(artifact)

    if arguments.check:
        if not ARTIFACT_PATH.is_file():
            print(f"MISSING: {ARTIFACT_PATH}", file=sys.stderr)
            return 1
        committed = ARTIFACT_PATH.read_text(encoding="utf-8")
        if committed != payload:
            print(
                f"STALE: {ARTIFACT_PATH} does not match the seed data.\n"
                "Run: python -m scripts.train_category_model",
                file=sys.stderr,
            )
            return 1
        print(f"OK: artifact matches seed data ({artifact['vocabulary_size']} features)")
        return 0

    ARTIFACT_PATH.parent.mkdir(parents=True, exist_ok=True)
    ARTIFACT_PATH.write_text(payload, encoding="utf-8")
    class_counts = dict(zip(artifact["categories"], artifact["document_counts"], strict=True))
    print(
        f"wrote {ARTIFACT_PATH.relative_to(REPO_ROOT)}: "
        f"{len(rows)} documents, {artifact['vocabulary_size']} features, "
        f"class counts {class_counts}"
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
