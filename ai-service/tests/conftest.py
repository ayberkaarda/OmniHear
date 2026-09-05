"""Shared pytest fixtures: a TestClient and an HMAC signing helper.

Signing uses the service's own configured secret (app.config.settings),
so tests remain correct regardless of what AI_SERVICE_HMAC_SECRET is set
to in the environment they run in.
"""

import hashlib
import hmac
import json
from pathlib import Path

import pytest
from fastapi.testclient import TestClient

from app.config import settings
from app.main import app

DEFAULT_CORRELATION_ID = "11111111-1111-1111-1111-111111111111"


@pytest.fixture
def client() -> TestClient:
    return TestClient(app)


def sign(body: bytes) -> str:
    """Compute the X-Signature value for a raw request body."""
    return hmac.new(
        settings.ai_service_hmac_secret.encode("utf-8"), body, hashlib.sha256
    ).hexdigest()


@pytest.fixture
def make_headers():
    """Returns a factory: body bytes -> valid {X-Correlation-Id, X-Signature} headers."""

    def _make(body: bytes, correlation_id: str = DEFAULT_CORRELATION_ID) -> dict[str, str]:
        return {
            "X-Correlation-Id": correlation_id,
            "X-Signature": sign(body),
        }

    return _make


# --- Contract fixture access -------------------------------------------------
#
# contracts/fixtures/analyze/ is the shared Laravel <-> FastAPI fixture
# directory (CONTRIBUTING.md §2: for a shape a fixture covers, no test may treat
# its own inline JSON as proof). Everything below exists so tests read
# those files instead of restating the shapes.

FIXTURES_DIR = Path(__file__).resolve().parent.parent.parent / "contracts" / "fixtures" / "analyze"


def load_case(name: str) -> dict:
    """Load one scenario file by name (without the .json suffix)."""
    return json.loads((FIXTURES_DIR / f"{name}.json").read_text(encoding="utf-8"))


def all_cases() -> list[dict]:
    """Every scenario, ordered by filename so test ids stay stable."""
    return [
        json.loads(path.read_text(encoding="utf-8")) for path in sorted(FIXTURES_DIR.glob("*.json"))
    ]


def case_ids(cases: list[dict]) -> list[str]:
    return [case["name"] for case in cases]


def send_case(client: TestClient, case: dict):
    """Replay a scenario against the app exactly as its file describes it."""
    body = json.dumps(case["request"], ensure_ascii=False).encode("utf-8")

    headers: dict[str, str] = {}
    if case["correlation_id"] is not None:
        headers["X-Correlation-Id"] = case["correlation_id"]

    signature_mode = case["signature"]
    if signature_mode == "valid":
        headers["X-Signature"] = sign(body)
    elif signature_mode == "tampered":
        headers["X-Signature"] = "0" * 64
    elif signature_mode != "absent":
        raise ValueError(f"{case['name']}: unknown signature mode {signature_mode!r}")

    return client.post(case["endpoint"], content=body, headers=headers)


def key_shape(value):
    """Recursively reduce a JSON value to just its structure.

    Objects become {key: shape}, lists become the shape of their first
    element (or None when empty), scalars become their type name. This is
    what the fixture tests compare, so a changed *value* is fine and a
    changed *field set or type* is a failure.
    """
    if isinstance(value, dict):
        return {key: key_shape(value[key]) for key in sorted(value)}
    if isinstance(value, list):
        return ["<empty>"] if not value else [key_shape(value[0])]
    if isinstance(value, bool):
        return "bool"
    if isinstance(value, int):
        # JSON has one number type; an int where the model declares a float
        # is not a contract break (0 and 0.0 serialise differently).
        return "number"
    if isinstance(value, float):
        return "number"
    if value is None:
        return "null"
    return type(value).__name__
