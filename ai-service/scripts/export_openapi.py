"""Export the FastAPI OpenAPI schema to contracts/ai-openapi.json.

    python -m scripts.export_openapi            # write the file
    python -m scripts.export_openapi --check    # fail if the committed file is stale

``contracts/ai-openapi.json`` is named by CONTRIBUTING.md as *the*
Laravel <-> FastAPI contract, so it must never be hand-edited: it is
generated from the live Pydantic models, and ``--check`` (called from the
test suite) turns "the schema changed but the contract file did not" into
a red test instead of a Laravel-side 422 discovered in staging.

``info.version`` is stripped before writing, per the ``ai-contract-sync``
skill: it changes on every build and would otherwise produce a noisy diff
on a file two teams read. Version tracking that carries meaning lives in
the ``model_version`` response field, not here.
"""

from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from app.main import app  # noqa: E402

REPO_ROOT = Path(__file__).resolve().parent.parent
CONTRACT_PATH = REPO_ROOT.parent / "contracts" / "ai-openapi.json"


def build_schema() -> dict:
    """Return the normalised OpenAPI document."""
    schema = app.openapi()
    schema["info"].pop("version", None)
    return schema


def serialize(schema: dict) -> str:
    return json.dumps(schema, ensure_ascii=False, indent=2, sort_keys=True) + "\n"


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument(
        "--check",
        action="store_true",
        help="verify the committed contract matches the live schema",
    )
    arguments = parser.parse_args()

    payload = serialize(build_schema())

    if arguments.check:
        if not CONTRACT_PATH.is_file():
            print(f"MISSING: {CONTRACT_PATH}", file=sys.stderr)
            return 1
        if CONTRACT_PATH.read_text(encoding="utf-8") != payload:
            print(
                f"STALE: {CONTRACT_PATH} does not match the FastAPI schema.\n"
                "Run: python -m scripts.export_openapi",
                file=sys.stderr,
            )
            return 1
        print(f"OK: {CONTRACT_PATH.name} matches the live schema")
        return 0

    CONTRACT_PATH.parent.mkdir(parents=True, exist_ok=True)
    CONTRACT_PATH.write_text(payload, encoding="utf-8")
    byte_count = len(payload.encode("utf-8"))
    print(f"wrote {CONTRACT_PATH} ({byte_count} bytes)")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
