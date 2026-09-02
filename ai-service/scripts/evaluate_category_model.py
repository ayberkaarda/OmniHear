"""Evaluate the category classifier and print confusion matrices.

    python -m scripts.evaluate_category_model                    # held-out eval set
    python -m scripts.evaluate_category_model --dataset seed     # training set (sanity only)
    python -m scripts.evaluate_category_model --markdown         # MODEL_CARD.md tables

Prints one confusion matrix for Turkish and one for English, plus
per-class precision/recall/F1. ADR-0004 names classifier overfitting as
the highest risk of this phase, so the split matters: ``data/category_eval.jsonl``
was written independently of ``data/category_seed.jsonl`` and shares no
sentences with it. Accuracy on ``--dataset seed`` is reported only to show
how far the held-out number falls below it — the gap *is* the overfitting
measurement.

The output of this script is pasted into MODEL_CARD.md, honestly,
including the classes where it does badly.
"""

from __future__ import annotations

import argparse
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from app.analyzers.category import CategoryClassifier  # noqa: E402
from app.schemas import Category  # noqa: E402
from scripts.train_category_model import REPO_ROOT, load_dataset  # noqa: E402

EVAL_PATH = REPO_ROOT / "data" / "category_eval.jsonl"
SEED_PATH = REPO_ROOT / "data" / "category_seed.jsonl"

CATEGORIES = sorted(category.value for category in Category)

# Short column headers so the matrix fits in a terminal and a README table.
ABBREVIATIONS = {
    "bug": "bug",
    "complaint": "cmpl",
    "feature_request": "feat",
    "praise": "prai",
}


def confusion_matrix(
    rows: list[dict[str, str]], classifier: CategoryClassifier
) -> dict[str, dict[str, int]]:
    """Rows are truth, columns are prediction."""
    matrix = {truth: dict.fromkeys(CATEGORIES, 0) for truth in CATEGORIES}
    for row in rows:
        predicted = classifier.classify(row["text"]).category.value
        matrix[row["category"]][predicted] += 1
    return matrix


def accuracy(matrix: dict[str, dict[str, int]]) -> tuple[int, int]:
    correct = sum(matrix[label][label] for label in CATEGORIES)
    total = sum(sum(row.values()) for row in matrix.values())
    return correct, total


def per_class_metrics(matrix: dict[str, dict[str, int]]) -> dict[str, tuple[float, float, float]]:
    """Return {label: (precision, recall, f1)}."""
    metrics: dict[str, tuple[float, float, float]] = {}
    for label in CATEGORIES:
        true_positive = matrix[label][label]
        predicted_positive = sum(matrix[truth][label] for truth in CATEGORIES)
        actual_positive = sum(matrix[label].values())
        precision = true_positive / predicted_positive if predicted_positive else 0.0
        recall = true_positive / actual_positive if actual_positive else 0.0
        f1 = 2 * precision * recall / (precision + recall) if (precision + recall) else 0.0
        metrics[label] = (precision, recall, f1)
    return metrics


def render_matrix(title: str, matrix: dict[str, dict[str, int]], markdown: bool) -> str:
    correct, total = accuracy(matrix)
    percentage = 100.0 * correct / total if total else 0.0
    header = [""] + [ABBREVIATIONS[label] for label in CATEGORIES]
    body = [
        [ABBREVIATIONS[truth]] + [str(matrix[truth][predicted]) for predicted in CATEGORIES]
        for truth in CATEGORIES
    ]

    lines = [f"{title} — accuracy {correct}/{total} ({percentage:.1f}%)", ""]
    if markdown:
        lines.append("| truth \\ pred | " + " | ".join(header[1:]) + " |")
        lines.append("|---" * (len(header)) + "|")
        lines.extend("| " + " | ".join(row) + " |" for row in body)
    else:
        widths = [max(len(header[i]), *(len(row[i]) for row in body)) for i in range(len(header))]
        lines.append("  ".join(cell.rjust(widths[i]) for i, cell in enumerate(header)))
        lines.extend("  ".join(cell.rjust(widths[i]) for i, cell in enumerate(row)) for row in body)

    lines.append("")
    metrics = per_class_metrics(matrix)
    if markdown:
        lines.append("| class | precision | recall | f1 |")
        lines.append("|---|---|---|---|")
        lines.extend(
            f"| {label} | {metrics[label][0]:.2f} | {metrics[label][1]:.2f} "
            f"| {metrics[label][2]:.2f} |"
            for label in CATEGORIES
        )
    else:
        lines.append(f"{'class':<16} {'prec':>5} {'rec':>5} {'f1':>5}")
        lines.extend(
            f"{label:<16} {metrics[label][0]:>5.2f} {metrics[label][1]:>5.2f} "
            f"{metrics[label][2]:>5.2f}"
            for label in CATEGORIES
        )
    return "\n".join(lines)


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--dataset", choices=("eval", "seed"), default="eval")
    parser.add_argument("--markdown", action="store_true", help="emit MODEL_CARD.md tables")
    arguments = parser.parse_args()

    path = EVAL_PATH if arguments.dataset == "eval" else SEED_PATH
    rows = load_dataset(path)
    classifier = CategoryClassifier()

    print(f"dataset: {path.relative_to(REPO_ROOT)} ({len(rows)} rows)")
    print(f"artifact fingerprint: {classifier.fingerprint[:16]}")
    print()

    for language, title in (("tr", "Turkish"), ("en", "English")):
        subset = [row for row in rows if row["language"] == language]
        print(render_matrix(title, confusion_matrix(subset, classifier), arguments.markdown))
        print()

    print(render_matrix("Combined", confusion_matrix(rows, classifier), arguments.markdown))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
