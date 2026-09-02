"""Contract tests driven by contracts/fixtures/analyze/.

These are the fixtures the Laravel side consumes from F5 onwards. Both
sides reading the same files is what makes the contract a contract, so
nothing here restates a request or response shape inline — every shape
comes out of a file (CLAUDE.md §2).

Three independent things are checked:

1. **The fixtures are valid against the Pydantic models.** A fixture that
   does not satisfy `AnalyzeResponse` would hand Laravel a shape the
   service can never produce.
2. **The live service still produces that shape.** Replaying each
   scenario and comparing the *structure* of the response — key sets and
   value types, not values — is the drift detector. A renamed field, a
   dropped field or a type change fails here.
3. **Field-level invariants hold on live output**, so a model change that
   keeps the shape but breaks a bound (a score of 1.4) is still caught.

Values are deliberately not asserted equal: `sentiment_score` and friends
move whenever the model is retrained, and `model_version` differs between
the ONNX and lexicon backends. See the fixtures README for what is
normative.
"""

import pytest
from fastapi.testclient import TestClient
from pydantic import TypeAdapter

from app.schemas import (
    AnalyzeRequest,
    AnalyzeResponse,
    BatchAnalyzeRequest,
    BatchAnalyzeResponse,
    ErrorResponse,
)
from tests.conftest import all_cases, case_ids, key_shape, load_case, send_case

CASES = all_cases()

REQUEST_MODELS = {"/v1/analyze": AnalyzeRequest, "/v1/analyze/batch": BatchAnalyzeRequest}
RESPONSE_MODELS = {"/v1/analyze": AnalyzeResponse, "/v1/analyze/batch": BatchAnalyzeResponse}

# Scenarios the fixture directory must contain. Listed here so that
# deleting a fixture file is a test failure rather than a silent loss of
# coverage.
REQUIRED_CASES = {
    "single-tr-complaint",
    "single-en-praise",
    "single-bug-report",
    "single-tr-feature-request",
    "single-neutral-ambiguous",
    "single-edge-emoji-only",
    "single-wrong-language-hint",
    "batch-fifty-items",
    "error-invalid-signature",
    "error-validation-error",
    "error-batch-too-large",
}


def test_every_required_scenario_has_a_fixture() -> None:
    assert REQUIRED_CASES <= {case["name"] for case in CASES}


def test_fixture_directory_is_not_empty() -> None:
    assert CASES, "contracts/fixtures/analyze/ contains no scenarios"


@pytest.mark.parametrize("case", CASES, ids=case_ids(CASES))
def test_fixture_name_matches_its_filename(case: dict) -> None:
    assert load_case(case["name"])["name"] == case["name"]


@pytest.mark.parametrize("case", CASES, ids=case_ids(CASES))
def test_fixture_request_validates_against_the_request_model(case: dict) -> None:
    """Error fixtures are exempt: their whole purpose is an invalid body."""
    if case["status"] != 200:
        return
    REQUEST_MODELS[case["endpoint"]].model_validate(case["request"])


@pytest.mark.parametrize("case", CASES, ids=case_ids(CASES))
def test_fixture_response_validates_against_the_response_model(case: dict) -> None:
    model = RESPONSE_MODELS[case["endpoint"]] if case["status"] == 200 else ErrorResponse
    TypeAdapter(model).validate_python(case["response"])


@pytest.mark.parametrize("case", CASES, ids=case_ids(CASES))
def test_live_response_matches_the_fixture_status(client: TestClient, case: dict) -> None:
    assert send_case(client, case).status_code == case["status"]


@pytest.mark.parametrize("case", CASES, ids=case_ids(CASES))
def test_live_response_shape_has_not_drifted(client: TestClient, case: dict) -> None:
    live = send_case(client, case).json()
    assert key_shape(live) == key_shape(case["response"])


@pytest.mark.parametrize(
    "case",
    [case for case in CASES if case["status"] != 200],
    ids=case_ids([case for case in CASES if case["status"] != 200]),
)
def test_live_error_code_matches_the_fixture(client: TestClient, case: dict) -> None:
    """`code` is contractual; `message` explicitly is not."""
    assert send_case(client, case).json()["code"] == case["response"]["code"]


@pytest.mark.parametrize(
    "case",
    [case for case in CASES if case["status"] == 200],
    ids=case_ids([case for case in CASES if case["status"] == 200]),
)
def test_live_success_response_honours_declared_bounds(client: TestClient, case: dict) -> None:
    live = send_case(client, case).json()
    results = live["results"] if case["endpoint"].endswith("/batch") else [live]

    for result in results:
        assert -1.0 <= result["sentiment_score"] <= 1.0
        assert 0.0 <= result["confidence"] <= 1.0
        assert result["sentiment_label"] in {"positive", "neutral", "negative"}
        assert result["category"] in {"complaint", "praise", "bug", "feature_request"}
        assert len(result["keywords"]) <= 10
        assert len(result["language"]) == 2
        assert isinstance(result["model_version"], str) and result["model_version"]

    assert live["correlation_id"] == case["correlation_id"]


def test_batch_fixture_sits_exactly_on_the_documented_limit() -> None:
    case = load_case("batch-fifty-items")
    assert len(case["request"]["items"]) == 50
    assert len(case["response"]["results"]) == 50


def test_batch_too_large_fixture_is_one_over_the_limit() -> None:
    assert len(load_case("error-batch-too-large")["request"]["items"]) == 51


def test_batch_envelope_model_version_agrees_with_every_item(
    client: TestClient,
) -> None:
    live = send_case(client, load_case("batch-fifty-items")).json()
    assert {result["model_version"] for result in live["results"]} == {live["model_version"]}


def test_batch_preserves_item_ids_and_order(client: TestClient) -> None:
    case = load_case("batch-fifty-items")
    live = send_case(client, case).json()
    assert [result["id"] for result in live["results"]] == [
        item["id"] for item in case["request"]["items"]
    ]


def test_emoji_only_fixture_yields_no_keywords(client: TestClient) -> None:
    """The edge case the spec calls out: a body with no letters at all."""
    live = send_case(client, load_case("single-edge-emoji-only")).json()
    assert live["keywords"] == []


def test_wrong_language_hint_does_not_win_over_detection(client: TestClient) -> None:
    """Turkish text hinted as 'en' must still be reported as Turkish."""
    live = send_case(client, load_case("single-wrong-language-hint")).json()
    assert load_case("single-wrong-language-hint")["request"]["language_hint"] == "en"
    assert live["language"] == "tr"
