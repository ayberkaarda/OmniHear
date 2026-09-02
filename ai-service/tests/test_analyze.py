"""Tests for POST /v1/analyze and /v1/analyze/batch, plus a direct model
test proving the AnalysisResult schema enforces its declared bounds.
"""

import json

import pytest
from fastapi.testclient import TestClient
from pydantic import ValidationError

from app.schemas import AnalysisResult, Category, SentimentLabel

VALID_LABELS = {"positive", "neutral", "negative"}
VALID_CATEGORIES = {"complaint", "praise", "bug", "feature_request"}


def test_analyze_valid_signature_and_body_returns_contract_shaped_response(
    client: TestClient, make_headers
) -> None:
    body = json.dumps({"text": "Bu urunu cok sevdim, harika!", "language_hint": "tr"}).encode(
        "utf-8"
    )
    headers = make_headers(body)

    response = client.post("/v1/analyze", content=body, headers=headers)

    assert response.status_code == 200
    data = response.json()
    assert -1.0 <= data["sentiment_score"] <= 1.0
    assert data["sentiment_label"] in VALID_LABELS
    assert data["category"] in VALID_CATEGORIES
    assert 0.0 <= data["confidence"] <= 1.0
    assert isinstance(data["keywords"], list)
    assert len(data["keywords"]) <= 10
    assert len(data["language"]) == 2
    assert data["model_version"]
    assert data["correlation_id"] == headers["X-Correlation-Id"]


def test_analyze_is_deterministic_for_same_input(client: TestClient, make_headers) -> None:
    body = json.dumps({"text": "the exact same input text"}).encode("utf-8")
    headers = make_headers(body)

    first = client.post("/v1/analyze", content=body, headers=headers).json()
    second = client.post("/v1/analyze", content=body, headers=headers).json()

    for key in ("sentiment_score", "sentiment_label", "category", "confidence", "keywords"):
        assert first[key] == second[key]


def test_analyze_invalid_signature_returns_401(client: TestClient, make_headers) -> None:
    body = json.dumps({"text": "hello"}).encode("utf-8")
    headers = make_headers(body)
    headers["X-Signature"] = "0" * 64  # well-formed hex, but wrong

    response = client.post("/v1/analyze", content=body, headers=headers)

    assert response.status_code == 401
    assert response.json()["code"] == "INVALID_SIGNATURE"


def test_analyze_missing_signature_header_returns_401(client: TestClient) -> None:
    body = json.dumps({"text": "hello"}).encode("utf-8")

    response = client.post("/v1/analyze", content=body, headers={"X-Correlation-Id": "some-id"})

    assert response.status_code == 401
    assert response.json()["code"] == "INVALID_SIGNATURE"


def test_analyze_empty_text_returns_422(client: TestClient, make_headers) -> None:
    body = json.dumps({"text": ""}).encode("utf-8")
    headers = make_headers(body)

    response = client.post("/v1/analyze", content=body, headers=headers)

    assert response.status_code == 422
    assert response.json()["code"] == "VALIDATION_ERROR"


def test_batch_analyze_fifty_items_returns_200(client: TestClient, make_headers) -> None:
    items = [{"id": str(i), "text": f"item number {i}"} for i in range(50)]
    body = json.dumps({"items": items}).encode("utf-8")
    headers = make_headers(body)

    response = client.post("/v1/analyze/batch", content=body, headers=headers)

    assert response.status_code == 200
    data = response.json()
    assert len(data["results"]) == 50
    assert data["correlation_id"] == headers["X-Correlation-Id"]
    assert [r["id"] for r in data["results"]] == [str(i) for i in range(50)]


def test_batch_analyze_fifty_one_items_returns_422_batch_too_large(
    client: TestClient, make_headers
) -> None:
    items = [{"id": str(i), "text": f"item number {i}"} for i in range(51)]
    body = json.dumps({"items": items}).encode("utf-8")
    headers = make_headers(body)

    response = client.post("/v1/analyze/batch", content=body, headers=headers)

    assert response.status_code == 422
    assert response.json()["code"] == "BATCH_TOO_LARGE"


def test_analysis_result_rejects_out_of_range_sentiment_score() -> None:
    """Direct model test: even if an analyzer misbehaves and returns 1.5,
    Pydantic must refuse to construct the result."""
    with pytest.raises(ValidationError):
        AnalysisResult(
            sentiment_score=1.5,
            sentiment_label=SentimentLabel.POSITIVE,
            category=Category.PRAISE,
            confidence=0.5,
            keywords=[],
            language="en",
            model_version="stub-0.1.0",
        )
