"""Compare the sentiment backends on the labelled corpora.

    python -m scripts.evaluate_sentiment
    python -m scripts.evaluate_sentiment --dataset seed

The corpora are labelled by *category*, not by sentiment, so this script
evaluates only the two categories whose polarity is unambiguous:

* `praise`               -> expected positive
* `bug` and `complaint`  -> expected negative

`feature_request` is excluded on purpose. "Karanlık mod eklerseniz çok
sevinirim" is a polite request, and whether that is positive or neutral is
a judgement call, not ground truth — scoring against a coin-flip label
would make the comparison look more precise than it is.

This is a *relative* measurement. Its job is to quantify what the lexicon
fallback costs against the ONNX backend, honestly, on the same rows. Both
numbers appear in MODEL_CARD.md.
"""

from __future__ import annotations

import argparse
import sys
from dataclasses import dataclass
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from app.analyzers.language import detect  # noqa: E402
from app.analyzers.sentiment import SentimentBackend  # noqa: E402
from app.analyzers.sentiment_lexicon import LexiconSentimentBackend  # noqa: E402
from app.analyzers.sentiment_onnx import (  # noqa: E402
    OnnxSentimentBackend,
    missing_requirements,
)
from app.config import settings  # noqa: E402
from app.schemas import SentimentLabel  # noqa: E402
from scripts.train_category_model import REPO_ROOT, load_dataset  # noqa: E402

EXPECTED_POLARITY = {
    "praise": SentimentLabel.POSITIVE,
    "bug": SentimentLabel.NEGATIVE,
    "complaint": SentimentLabel.NEGATIVE,
}


@dataclass
class Tally:
    """Correct / abstained / wrong-sign counts.

    The three-way split matters more than the accuracy figure. A backend
    that answers "neutral" on a negative review is unhelpful but harmless;
    one that answers "positive" has actively misinformed the dashboard.
    Collapsing both into "incorrect" hides the difference.
    """

    correct: int = 0
    abstained: int = 0
    wrong_sign: int = 0

    @property
    def total(self) -> int:
        return self.correct + self.abstained + self.wrong_sign

    @property
    def accuracy(self) -> float:
        return self.correct / self.total if self.total else 0.0

    @property
    def precision_when_committed(self) -> float:
        """Accuracy over the rows where the backend did pick a side."""
        committed = self.correct + self.wrong_sign
        return self.correct / committed if committed else 0.0


def evaluate(backend: SentimentBackend, rows: list[dict[str, str]]) -> dict[str, Tally]:
    """Return a Tally for 'tr', 'en' and 'all'."""
    tallies = {"tr": Tally(), "en": Tally(), "all": Tally()}

    for row in rows:
        expected = EXPECTED_POLARITY.get(row["category"])
        if expected is None:
            continue
        language = detect(row["text"], row["language"]).language
        predicted = backend.score(row["text"], language).label

        for bucket in (row["language"], "all"):
            tally = tallies[bucket]
            if predicted is expected:
                tally.correct += 1
            elif predicted is SentimentLabel.NEUTRAL:
                tally.abstained += 1
            else:
                tally.wrong_sign += 1

    return tallies


def report(name: str, results: dict[str, Tally]) -> None:
    print(f"{name}:")
    print(f"  {'':<5} {'acc':>16}  {'abstain':>9}  {'wrong':>7}  {'prec|committed':>14}")
    for bucket in ("tr", "en", "all"):
        tally = results[bucket]
        print(
            f"  {bucket:<5} "
            f"{tally.correct:>3}/{tally.total:<3} ({100 * tally.accuracy:5.1f}%)  "
            f"{tally.abstained:>9}  {tally.wrong_sign:>7}  "
            f"{100 * tally.precision_when_committed:>13.1f}%"
        )


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--dataset", choices=("eval", "seed"), default="eval")
    arguments = parser.parse_args()

    path = REPO_ROOT / "data" / f"category_{arguments.dataset}.jsonl"
    rows = load_dataset(path)
    scored = [row for row in rows if row["category"] in EXPECTED_POLARITY]

    print(f"dataset: {path.relative_to(REPO_ROOT)}")
    print(f"rows with an unambiguous polarity label: {len(scored)} of {len(rows)}")
    print("(feature_request excluded — see this script's docstring)\n")

    report("lexicon backend", evaluate(LexiconSentimentBackend(), rows))

    reasons = missing_requirements(settings.sentiment_model_dir)
    if reasons:
        print(f"\nonnx backend: SKIPPED ({'; '.join(reasons)})")
        return 0

    print()
    report("onnx backend", evaluate(OnnxSentimentBackend(settings.sentiment_model_dir), rows))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
