"""Shared pytest fixtures: a TestClient and an HMAC signing helper.

Signing uses the service's own configured secret (app.config.settings),
so tests remain correct regardless of what AI_SERVICE_HMAC_SECRET is set
to in the environment they run in.
"""

import hashlib
import hmac

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
