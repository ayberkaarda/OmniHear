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
