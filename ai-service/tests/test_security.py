"""Focused tests for HMAC signature verification (app.security)."""

import json

from fastapi.testclient import TestClient

from tests.conftest import sign


def test_valid_signature_is_accepted(client: TestClient, make_headers) -> None:
    body = json.dumps({"text": "signed correctly"}).encode("utf-8")
    headers = make_headers(body)

    response = client.post("/v1/analyze", content=body, headers=headers)

    assert response.status_code == 200


def test_signature_computed_over_wrong_body_is_rejected(client: TestClient, make_headers) -> None:
    signed_body = json.dumps({"text": "original"}).encode("utf-8")
    headers = make_headers(signed_body)
    tampered_body = json.dumps({"text": "tampered"}).encode("utf-8")

    response = client.post("/v1/analyze", content=tampered_body, headers=headers)

    assert response.status_code == 401
    assert response.json()["code"] == "INVALID_SIGNATURE"


def test_missing_signature_header_is_rejected(client: TestClient) -> None:
    body = json.dumps({"text": "no signature at all"}).encode("utf-8")

    response = client.post("/v1/analyze", content=body, headers={"X-Correlation-Id": "abc-123"})

    assert response.status_code == 401
    assert response.json()["code"] == "INVALID_SIGNATURE"
    body_json = response.json()
    assert body_json["correlation_id"] == "abc-123"


def test_missing_correlation_id_header_is_rejected(client: TestClient) -> None:
    body = json.dumps({"text": "signed but no correlation id"}).encode("utf-8")

    response = client.post("/v1/analyze", content=body, headers={"X-Signature": sign(body)})

    assert response.status_code == 422
    assert response.json()["code"] == "VALIDATION_ERROR"


# A rejected request must not echo the offending field's value back in the
# response body. `str(pydantic.ValidationError)` embeds `input_value`, so a
# naive `"message": str(exc)` reflects arbitrary client input to the caller.
# Distinctive enough that an accidental substring match is not plausible.
MARKER = "ZZZ_INPUT_ECHO_MARKER_9f31c2"


def test_a_single_analyze_validation_failure_does_not_echo_the_input(
    client: TestClient, make_headers
) -> None:
    """`language_hint` fails type validation, and its value carries the
    marker; the marker must not appear anywhere in the response body."""
    body = json.dumps({"text": "ordinary feedback text", "language_hint": {"x": MARKER}}).encode(
        "utf-8"
    )

    response = client.post("/v1/analyze", content=body, headers=make_headers(body))

    assert response.status_code == 422
    assert response.json()["code"] == "VALIDATION_ERROR"
    assert MARKER not in response.text


def test_a_batch_analyze_validation_failure_does_not_echo_the_input(
    client: TestClient, make_headers
) -> None:
    """Same as above, but for a validation error on `items[i].language_hint`,
    to confirm the batch endpoint's error path is covered too."""
    items = [{"id": "fb_1", "text": "ordinary text", "language_hint": {"x": MARKER}}]
    body = json.dumps({"items": items}).encode("utf-8")

    response = client.post("/v1/analyze/batch", content=body, headers=make_headers(body))

    assert response.status_code == 422
    assert response.json()["code"] == "VALIDATION_ERROR"
    assert MARKER not in response.text


def test_the_fastapi_level_validation_handler_does_not_echo_the_input() -> None:
    """`app.main.validation_exception_handler` is a safety net for
    validation errors FastAPI raises outside the manual body parsing in
    app.routers.analyze (see its docstring) — no live route in this
    service currently reaches it, but it carries the same `str(exc)`
    input-echo bug and must be fixed and tested directly."""
    import asyncio

    from fastapi.exceptions import RequestValidationError

    from app.main import validation_exception_handler

    exc = RequestValidationError(
        errors=[
            {
                "type": "string_type",
                "loc": ("language_hint",),
                "msg": "Input should be a valid string",
                "input": {"x": MARKER},
            }
        ]
    )

    response = asyncio.run(validation_exception_handler(None, exc))  # type: ignore[arg-type]

    assert response.status_code == 422
    assert MARKER not in response.body.decode("utf-8")
