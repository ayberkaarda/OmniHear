"""Feedback text must never reach a log line.

`feedbacks.body` is PII under KVKK (spec §8) and invariant I5 forbids
logging it. The analyze router documents which fields are permitted;
these tests enforce that documentation rather than trusting it, by
capturing everything the service logs during a real request and searching
it for the input text.

The check runs against the *formatted* record, not just the message, so a
field smuggled in through `extra=` is caught too.
"""

import json
import logging

import pytest
from fastapi.testclient import TestClient

# Distinctive enough that an accidental substring match is not plausible.
SECRET_TEXT = "Zamboni kalibrasyonu bozuldu ve QuixoticWidget9271 patladi"
SECRET_TOKENS = ("Zamboni", "QuixoticWidget9271", "kalibrasyonu")


@pytest.fixture
def captured_logs(caplog: pytest.LogCaptureFixture) -> pytest.LogCaptureFixture:
    caplog.set_level(logging.DEBUG)
    return caplog


def _rendered(caplog: pytest.LogCaptureFixture) -> str:
    """Every captured record, formatted, plus all of its extra attributes."""
    formatter = logging.Formatter("%(name)s %(levelname)s %(message)s")
    chunks: list[str] = []
    for record in caplog.records:
        chunks.append(formatter.format(record))
        chunks.append(repr(record.__dict__))
    return "\n".join(chunks)


def test_single_analyze_does_not_log_the_request_text(
    client: TestClient, make_headers, captured_logs: pytest.LogCaptureFixture
) -> None:
    body = json.dumps({"text": SECRET_TEXT, "language_hint": "tr"}).encode("utf-8")
    response = client.post("/v1/analyze", content=body, headers=make_headers(body))
    assert response.status_code == 200

    rendered = _rendered(captured_logs)
    for token in SECRET_TOKENS:
        assert token not in rendered


def test_batch_analyze_does_not_log_any_item_text(
    client: TestClient, make_headers, captured_logs: pytest.LogCaptureFixture
) -> None:
    items = [{"id": "fb_1", "text": SECRET_TEXT}, {"id": "fb_2", "text": "ordinary text"}]
    body = json.dumps({"items": items}).encode("utf-8")
    response = client.post("/v1/analyze/batch", content=body, headers=make_headers(body))
    assert response.status_code == 200

    rendered = _rendered(captured_logs)
    for token in SECRET_TOKENS:
        assert token not in rendered


def test_a_rejected_request_does_not_log_its_body(
    client: TestClient, captured_logs: pytest.LogCaptureFixture
) -> None:
    """The unauthenticated path is the one that matters most: an attacker
    must not be able to write chosen text into the logs."""
    body = json.dumps({"text": SECRET_TEXT}).encode("utf-8")
    response = client.post(
        "/v1/analyze",
        content=body,
        headers={"X-Correlation-Id": "abc-123", "X-Signature": "0" * 64},
    )
    assert response.status_code == 401

    rendered = _rendered(captured_logs)
    for token in SECRET_TOKENS:
        assert token not in rendered


def test_a_validation_failure_does_not_echo_the_body_into_the_logs(
    client: TestClient, make_headers, captured_logs: pytest.LogCaptureFixture
) -> None:
    """Pydantic's error string embeds the offending input, and that string
    goes into the *response* — but it must not go into the logs."""
    body = json.dumps({"text": SECRET_TEXT, "language_hint": 12345}).encode("utf-8")
    response = client.post("/v1/analyze", content=body, headers=make_headers(body))
    assert response.status_code == 422

    rendered = _rendered(captured_logs)
    for token in SECRET_TOKENS:
        assert token not in rendered


def test_the_correlation_id_is_logged(
    client: TestClient, make_headers, captured_logs: pytest.LogCaptureFixture
) -> None:
    """The flip side of the rule: identification must still be possible,
    by correlation id rather than by content (spec §3.6)."""
    body = json.dumps({"text": "ordinary feedback text"}).encode("utf-8")
    correlation_id = "22222222-2222-2222-2222-222222222222"
    client.post(
        "/v1/analyze",
        content=body,
        headers={
            "X-Correlation-Id": correlation_id,
            "X-Signature": make_headers(body)["X-Signature"],
        },
    )
    assert correlation_id in _rendered(captured_logs)


def test_the_hmac_secret_never_appears_in_the_logs(
    client: TestClient, make_headers, captured_logs: pytest.LogCaptureFixture
) -> None:
    """Invariant I5 covers credentials as well as feedback text."""
    from app.config import settings

    body = json.dumps({"text": "ordinary feedback text"}).encode("utf-8")
    client.post("/v1/analyze", content=body, headers=make_headers(body))
    assert settings.ai_service_hmac_secret not in _rendered(captured_logs)
