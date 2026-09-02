"""Deterministic ``model_version`` derivation.

ADR-0004 requires ``model_version`` to be "a deterministic hash, so the
reprocess workflow is real" — that is, ``SELECT id FROM ai_analyses WHERE
model_version <> :current`` must be an honest list of rows whose result
would change if recomputed today. That only holds if the identifier moves
whenever *anything* that shapes the output moves.

The identifier is ``omnihear-<backend>-<12 hex chars>`` where the digest
covers:

* the **source** of every pipeline module — language detection, keyword
  extraction, both sentiment backends, the classifier reader, the
  pipeline itself. Changing a lexicon weight or a threshold changes the
  version, because it changes the answers.
* the **category artifact** digest, which changes when the seed data is
  edited and the model retrained.
* the **sentiment backend** digest — the weights file hashes for ONNX,
  the lexicon digest for the fallback.

Two things are deliberately excluded. ``app/schemas.py`` is out, because
adding an optional response field is a backwards-compatible contract
change that does not alter any existing value (see the
``ai-contract-sync`` skill's compatibility table). ``stub.py`` and
``base.py`` are out, because the stub is a test fake and the protocol is
an interface, and neither takes part in production inference.

Source bytes are read with ``\\r`` stripped: a repository cloned on
Windows with ``core.autocrlf`` set produces different bytes for the same
code than the Linux image does, and a ``model_version`` that depends on
line endings would be worse than useless.
"""

import hashlib
from pathlib import Path
from typing import Final

_PACKAGE_ROOT: Final = Path(__file__).resolve().parent

# Explicit, not a glob: what belongs in the version is a decision, and a
# glob would silently pull in anything a future contributor drops into the
# directory.
PIPELINE_SOURCE_FILES: Final = (
    "analyzers/category.py",
    "analyzers/keywords.py",
    "analyzers/language.py",
    "analyzers/pipeline.py",
    "analyzers/sentiment.py",
    "analyzers/sentiment_lexicon.py",
    "analyzers/sentiment_onnx.py",
    "analyzers/text.py",
)

_DIGEST_LENGTH: Final = 12


def pipeline_source_digest() -> str:
    """SHA-256 over the pipeline source files, line-ending normalised."""
    digest = hashlib.sha256()
    for relative_path in PIPELINE_SOURCE_FILES:
        path = _PACKAGE_ROOT / relative_path
        digest.update(relative_path.encode("utf-8"))
        digest.update(path.read_bytes().replace(b"\r\n", b"\n"))
    return digest.hexdigest()


def build_model_version(
    backend_id: str,
    sentiment_fingerprint: str,
    category_fingerprint: str,
) -> str:
    """Compose the public ``model_version`` string."""
    digest = hashlib.sha256()
    digest.update(pipeline_source_digest().encode("utf-8"))
    digest.update(b"|")
    digest.update(sentiment_fingerprint.encode("utf-8"))
    digest.update(b"|")
    digest.update(category_fingerprint.encode("utf-8"))
    return f"omnihear-{backend_id}-{digest.hexdigest()[:_DIGEST_LENGTH]}"
