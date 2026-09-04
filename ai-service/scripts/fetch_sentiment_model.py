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

Downloads are retried with exponential backoff and resumed from wherever a
previous attempt left off (HTTP Range) rather than restarted from zero —
a single ~170 MB file over a flaky connection is exactly the case where
that matters (see the truncated-download failure this was built to fix).
A file only ever lands at its final target path once its size **and**
its SHA-256 both match the pin; until then it lives under a ``.part``
suffix in the same directory, so ``skip {filename} (already present)``
can trust that anything it finds at the target is complete and verified.
"""

from __future__ import annotations

import argparse
import hashlib
import http.client
import socket
import sys
import time
import urllib.error
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

# Network I/O tuning. Bounded attempts + exponential backoff, not infinite
# retry: a permanently unreachable host must still fail the build.
_MAX_ATTEMPTS = 5
_BACKOFF_BASE_SECONDS = 2.0
_REQUEST_TIMEOUT_SECONDS = 30

# Caught around a single download attempt and retried. HTTPError is a
# URLError subclass, so a 5xx (or any non-4xx HTTP error) falls through to
# this bucket too — only a 4xx (other than 416, handled separately) is
# treated as permanent. ContentTooShortError is also a URLError subclass;
# it is what _fetch_once raises itself when a response body comes up short.
#
# OSError is the outer net, and it is here for a specific hole: ssl.SSLError
# (a mid-stream TLS failure, which is exactly what a dropped 168 MB download
# looks like on a bad link) is an OSError but *not* a ConnectionError, so the
# narrower entries below would have let it fail the build on the first try.
# The cost of the wider net is that a genuinely local fault - no space on the
# device, no permission to write the .part file - is retried five times before
# it gives up. That is noise in the log, not a wrong outcome, and it buys
# coverage of every transport failure this script cannot enumerate in advance.
_TRANSIENT_EXCEPTIONS: tuple[type[BaseException], ...] = (
    urllib.error.URLError,
    OSError,
    TimeoutError,
    socket.timeout,
    http.client.IncompleteRead,
    ConnectionError,
)


class _PermanentError(RuntimeError):
    """A download failure that retrying cannot fix (e.g. HTTP 404)."""


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


def _fetch_once(url: str, partial: Path, expected_size: int) -> int:
    """Perform one download attempt into ``partial``, resuming if possible.

    Returns the resulting size of ``partial`` on success. Raises
    ``_PermanentError`` for a non-retryable HTTP error (4xx other than 416).
    Any other failure is left to propagate as one of the exception types in
    ``_TRANSIENT_EXCEPTIONS`` (or a plain ``urllib.error.ContentTooShortError``
    that this function raises itself when the body comes up short), for the
    caller to retry.
    """
    offset = partial.stat().st_size if partial.is_file() else 0
    headers = {"Range": f"bytes={offset}-"} if offset else {}
    request = urllib.request.Request(url, headers=headers)
    try:
        # nosec: a pinned https URL to a public model registry, verified by
        # the sha256 check that follows.
        response = urllib.request.urlopen(request, timeout=_REQUEST_TIMEOUT_SECONDS)  # noqa: S310
    except urllib.error.HTTPError as exc:
        if offset and exc.code == 416:
            # Our partial is already at or past what the server has (e.g. it
            # changed, or we somehow over-wrote). Discard it and restart.
            print(f"     range not satisfiable at byte {offset}, restarting from 0")
            partial.unlink(missing_ok=True)
            return _fetch_once(url, partial, expected_size)
        if 400 <= exc.code < 500:
            raise _PermanentError(f"HTTP {exc.code} {exc.reason}") from exc
        raise

    status = getattr(response, "status", None) or response.getcode()
    if offset and status == 200:
        # The server ignored our Range header and is sending the full body
        # from byte 0 again. Appending onto the partial file would silently
        # produce a corrupt file, so start over instead.
        print("     server ignored Range header, restarting from byte 0")
        offset = 0
        mode = "wb"
    elif offset and status == 206:
        print(f"     resuming from byte {offset}")
        mode = "ab"
    elif offset:
        raise urllib.error.URLError(f"unexpected HTTP status {status} for a ranged request")
    else:
        mode = "wb"

    content_length = response.getheader("Content-Length")
    expected_total = offset + int(content_length) if content_length is not None else expected_size

    written = offset
    with partial.open(mode) as handle:
        while True:
            chunk = response.read(_CHUNK)
            if not chunk:
                break
            handle.write(chunk)
            written += len(chunk)

    if written < expected_total:
        raise urllib.error.ContentTooShortError(
            f"retrieval incomplete: got only {written} out of {expected_total} bytes",
            None,
        )
    return written


def _download_with_retries(filename: str, url: str, partial: Path, expected_size: int) -> None:
    for attempt in range(1, _MAX_ATTEMPTS + 1):
        print(f"get  {url} (attempt {attempt}/{_MAX_ATTEMPTS})")
        try:
            _fetch_once(url, partial, expected_size)
            return
        except _PermanentError as exc:
            raise RuntimeError(f"{filename}: permanent failure, not retrying: {exc}") from exc
        except _TRANSIENT_EXCEPTIONS as exc:
            if attempt == _MAX_ATTEMPTS:
                raise RuntimeError(
                    f"{filename}: giving up after {_MAX_ATTEMPTS} attempts: {exc}"
                ) from exc
            delay = _BACKOFF_BASE_SECONDS * (2 ** (attempt - 1))
            print(f"     attempt {attempt} failed ({exc}); retrying in {delay:.0f}s")
            time.sleep(delay)


def _fetch_file(
    filename: str,
    remote_path: str,
    expected_sha: str,
    expected_size: int,
    dest: Path,
    *,
    force: bool,
) -> None:
    target = dest / filename
    partial = dest / f"{filename}.part"

    if target.is_file() and not force:
        print(f"skip {filename} (already present)")
        return

    if force:
        target.unlink(missing_ok=True)
        partial.unlink(missing_ok=True)

    url = BASE_URL + remote_path
    _download_with_retries(filename, url, partial, expected_size)

    # Hash the whole finished file, not just whatever this attempt appended —
    # a resumed download must be verified end to end.
    actual_size = partial.stat().st_size
    actual_sha = sha256_of(partial)
    if actual_size != expected_size or actual_sha != expected_sha:
        partial.unlink(missing_ok=True)
        raise RuntimeError(
            f"{filename}: verification failed after download "
            f"(size {actual_size}, expected {expected_size}; "
            f"sha256 {actual_sha}, expected {expected_sha})"
        )

    partial.replace(target)
    print(f"     -> {target} ({actual_size} bytes, sha256 {actual_sha})")


def download(dest: Path, *, force: bool) -> None:
    dest.mkdir(parents=True, exist_ok=True)
    for filename, (remote_path, expected_sha, expected_size) in FILES.items():
        _fetch_file(filename, remote_path, expected_sha, expected_size, dest, force=force)


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
        try:
            download(arguments.dest, force=arguments.force)
        except RuntimeError as exc:
            print(f"FAIL {exc}", file=sys.stderr)
            return 1

    problems = verify(arguments.dest, strict=False)
    if problems:
        for problem in problems:
            print(f"FAIL {problem}", file=sys.stderr)
        return 1

    print(f"OK: weights verified in {arguments.dest}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
