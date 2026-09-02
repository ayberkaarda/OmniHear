"""contracts/ai-openapi.json must never drift from the Pydantic models.

CLAUDE.md names that file as *the* Laravel <-> FastAPI contract. It is
generated, not written, so the only failure mode left is forgetting to
regenerate it — which is what these tests turn red.
"""

import json

from app.openapi import REQUEST_BODY_MODELS
from scripts.export_openapi import CONTRACT_PATH, build_schema, serialize


def test_the_contract_file_exists() -> None:
    assert CONTRACT_PATH.is_file(), (
        f"{CONTRACT_PATH} is missing. Run: python -m scripts.export_openapi"
    )


def test_the_committed_contract_matches_the_live_schema() -> None:
    assert CONTRACT_PATH.read_text(encoding="utf-8") == serialize(build_schema()), (
        "contracts/ai-openapi.json is stale. Run: python -m scripts.export_openapi"
    )


def test_export_is_reproducible() -> None:
    assert serialize(build_schema()) == serialize(build_schema())


def test_info_version_is_normalised_away() -> None:
    """It changes on every build and would produce a noisy diff on a file
    two teams read (ai-contract-sync skill, step 2)."""
    assert "version" not in build_schema()["info"]


def test_every_endpoint_is_documented() -> None:
    assert set(build_schema()["paths"]) == {"/health", "/v1/analyze", "/v1/analyze/batch"}


def test_request_bodies_are_documented_despite_manual_parsing() -> None:
    """The routers HMAC-verify raw bytes before parsing, so FastAPI cannot
    discover these on its own (see app.openapi)."""
    schema = build_schema()
    for path, model_name in REQUEST_BODY_MODELS.items():
        body = schema["paths"][path]["post"]["requestBody"]
        assert body["required"] is True
        reference = body["content"]["application/json"]["schema"]["$ref"]
        assert reference == f"#/components/schemas/{model_name}"
        assert model_name in schema["components"]["schemas"]


def test_request_schemas_carry_the_field_constraints() -> None:
    """The bounds the Laravel FormRequest has to mirror must be visible."""
    schemas = build_schema()["components"]["schemas"]

    text = schemas["AnalyzeRequest"]["properties"]["text"]
    assert text["minLength"] == 1
    assert text["maxLength"] == 10000

    items = schemas["BatchAnalyzeRequest"]["properties"]["items"]
    assert items["minItems"] == 1
    assert items["maxItems"] == 50


def test_response_schemas_carry_the_field_constraints() -> None:
    properties = build_schema()["components"]["schemas"]["AnalyzeResponse"]["properties"]
    assert properties["sentiment_score"]["minimum"] == -1.0
    assert properties["sentiment_score"]["maximum"] == 1.0
    assert properties["confidence"]["minimum"] == 0.0
    assert properties["confidence"]["maximum"] == 1.0
    assert properties["keywords"]["maxItems"] == 10
    assert properties["language"]["minLength"] == 2
    assert properties["language"]["maxLength"] == 2


def test_error_responses_are_documented_on_both_analyze_endpoints() -> None:
    schema = build_schema()
    for path in REQUEST_BODY_MODELS:
        responses = schema["paths"][path]["post"]["responses"]
        assert {"200", "401", "422"} <= set(responses)
        for status in ("401", "422"):
            reference = responses[status]["content"]["application/json"]["schema"]["$ref"]
            assert reference == "#/components/schemas/ErrorResponse"


def test_fastapis_default_validation_error_is_not_advertised() -> None:
    """These endpoints emit {code, message, correlation_id}, never
    FastAPI's HTTPValidationError shape."""
    assert "HTTPValidationError" not in build_schema()["components"]["schemas"]


def test_security_headers_are_documented() -> None:
    parameters = build_schema()["paths"]["/v1/analyze"]["post"]["parameters"]
    names = {parameter["name"] for parameter in parameters}
    assert {"X-Signature", "X-Correlation-Id"} <= names


def test_enum_members_match_the_python_enums() -> None:
    schemas = build_schema()["components"]["schemas"]
    assert set(schemas["SentimentLabel"]["enum"]) == {"positive", "neutral", "negative"}
    assert set(schemas["Category"]["enum"]) == {
        "complaint",
        "praise",
        "bug",
        "feature_request",
    }


def test_committed_contract_is_valid_json() -> None:
    json.loads(CONTRACT_PATH.read_text(encoding="utf-8"))
