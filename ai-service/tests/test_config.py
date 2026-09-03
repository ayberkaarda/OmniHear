"""The analyzer must not boot without a real shared secret (invariant I7).

Until this existed, ``ai_service_hmac_secret`` defaulted to ``"REPLACE_ME"`` -
the literal printed in ``.env.example``, in a public repository. A deployment
that forgot the environment variable still verified signatures, against a key
anyone could read, which is the same as verifying nothing.
"""

import pytest
from pydantic import ValidationError

from app.config import Settings, settings


def _settings(**overrides):
    """Build Settings ignoring any .env file, so the test controls the input."""
    return Settings(_env_file=None, **overrides)


def test_missing_secret_is_refused(monkeypatch):
    monkeypatch.delenv("AI_SERVICE_HMAC_SECRET", raising=False)

    with pytest.raises(ValidationError):
        _settings()


@pytest.mark.parametrize("placeholder", ["REPLACE_ME", "replace_me", "CHANGE_ME", "", "   "])
def test_published_placeholder_is_refused(monkeypatch, placeholder):
    monkeypatch.setenv("AI_SERVICE_HMAC_SECRET", placeholder)

    with pytest.raises(ValidationError):
        _settings()


def test_a_real_secret_is_accepted(monkeypatch):
    monkeypatch.setenv("AI_SERVICE_HMAC_SECRET", "0123456789abcdef0123456789abcdef")

    assert _settings().ai_service_hmac_secret == "0123456789abcdef0123456789abcdef"


def test_the_running_configuration_holds_no_placeholder():
    assert settings.ai_service_hmac_secret.strip() != ""
    assert settings.ai_service_hmac_secret.strip().upper() != "REPLACE_ME"
