"""Download and checksum-verify the ONNX sentiment weights.

    python -m scripts.fetch_sentiment_model                  # into app's default model dir
    python -m scripts.fetch_sentiment_model --dest /opt/models/sentiment
    python -m scripts.fetch_sentiment_model --verify-only    # check an existing directory

**This runs at image build time, never at request time.** ADR-0004 makes
the weights a build artifact precisely so that the running service
performs no network I/O and stays stateless (invariant I6). Nothing in
``app/`` imports this module.

Every file is pinned by SHA-256. A CDN that serves different bytes than
the ones this pipeline was evaluated against would otherwise change every
analysis in the product without changing ``model_version`` — the hash
check is what makes "the weights are pinned" a fact rather than a hope.

Model: ``Xenova/bert-base-multilingual-uncased-sentiment`` (ONNX export of
``nlptown/bert-base-multilingual-uncased-sentiment``, MIT). Total download
~171 MB; the int8 quantised graph is used, not the 541 MB fp32 one.
"""

from __future__ import annotations

import argparse
import hashlib
import sys
import urllib.request
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from app.analyzers.sentiment_onnx import (  # noqa: E402
    CONFIG_FILENAME,
    MODEL_FILENAME,
    TOKENIZER_FILENAME,
)

REPO_ROOT = Path(__file__).resolve().parent.parent

HF_REPO = "Xenova/bert-base-multilingual-uncased-sentiment"
HF_REVISION = "main"
BASE_URL = f"https://huggingface.co/{HF_REPO}/resolve/{HF_REVISION}/"

# local filename -> (remote path, sha256, expected byte size)
FILES: dict[str, tuple[str, str, int]] = {
    MODEL_FILENAME: (
        "onnx/model_int8.onnx",
        "011c17a2902d1439f02ba6acfd90ed6ca4d3f5f059b9293e6bd999d094a87cf0",
        168123884,
    ),
    TOKENIZER_FILENAME: (
        "tokenizer.json",
        "11aaf894a4ccf3d95e8830e27c0f8152791fbbff2b988e29a265580b86edd216",
        2563370,
    ),
    CONFIG_FILENAME: (
        "config.json",
        "b27932c748cc010fb94185879253a7c708fc627e7dafa2b24e07e827e8421a33",
        1152,
    ),
}

_CHUNK = 1024 * 1024


def sha256_of(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(_CHUNK), b""):
            digest.update(chunk)
    return digest.hexdigest()


def verify(dest: Path, *, strict: bool) -> list[str]:
    """Return a list of problems; empty means the directory is good."""
    problems: list[str] = []
    for filename, (_, expected_sha, expected_size) in FILES.items():
        path = dest / filename
        if not path.is_file():
            problems.append(f"{filename}: missing")
            continue
        actual_size = path.stat().st_size
        if actual_size != expected_size:
            problems.append(f"{filename}: size {actual_size}, expected {expected_size}")
            continue
        if expected_sha.startswith("PLACEHOLDER"):
            if strict:
                problems.append(f"{filename}: no pinned sha256 recorded in this script")
            continue
        actual_sha = sha256_of(path)
        if actual_sha != expected_sha:
            problems.append(f"{filename}: sha256 {actual_sha}, expected {expected_sha}")
    return problems


def download(dest: Path, *, force: bool) -> None:
    dest.mkdir(parents=True, exist_ok=True)
    for filename, (remote_path, _, _) in FILES.items():
        target = dest / filename
        if target.is_file() and not force:
            print(f"skip {filename} (already present)")
            continue
        url = BASE_URL + remote_path
        print(f"get  {url}")
        # nosec: a pinned https URL to a public model registry, verified by
        # the sha256 check that follows.
        urllib.request.urlretrieve(url, target)  # noqa: S310
        print(f"     -> {target} ({target.stat().st_size} bytes, sha256 {sha256_of(target)})")


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument(
        "--dest",
        type=Path,
        default=REPO_ROOT / "models" / "sentiment",
        help="target directory for the weights",
    )
    parser.add_argument("--force", action="store_true", help="re-download existing files")
    parser.add_argument("--verify-only", action="store_true", help="do not download, only check")
    parser.add_argument(
        "--print-hashes",
        action="store_true",
        help="print the sha256 of each present file, to be pinned into FILES",
    )
    arguments = parser.parse_args()

    if arguments.print_hashes:
        for filename in FILES:
            path = arguments.dest / filename
            status = sha256_of(path) if path.is_file() else "MISSING"
            print(f"{filename}: {status}")
        return 0

    if not arguments.verify_only:
        download(arguments.dest, force=arguments.force)

    problems = verify(arguments.dest, strict=False)
    if problems:
        for problem in problems:
            print(f"FAIL {problem}", file=sys.stderr)
        return 1

    print(f"OK: weights verified in {arguments.dest}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
