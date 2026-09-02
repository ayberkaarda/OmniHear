"""Text analysis endpoints: single and batch.

Request bodies are parsed manually (via `model_validate_json`) rather than
through FastAPI's automatic body-parameter parsing, because the raw bytes
must be HMAC-verified (see app.security.verify_signature) before any JSON
deserialization happens.

Logging note: only `correlation_id`, request duration, and the resulting
`label`/`category` are ever logged. Raw text/body content is PII under
KVKK and must never reach the logs — see the module-level logger calls
below for the only fields that are permitted.
"""

import logging
import time

from fastapi import APIRouter, Depends, status
from pydantic import ValidationError

from app.analyzers.base import SentimentAnalyzer
from app.analyzers.registry import get_pipeline
from app.schemas import (
    AnalyzeRequest,
    AnalyzeResponse,
    BatchAnalyzeRequest,
    BatchAnalyzeResponse,
    BatchResultItem,
    ErrorResponse,
)
from app.security import ApiError, verify_signature

logger = logging.getLogger("ai_service.analyze")

router = APIRouter()

_BATCH_MAX_ITEMS = 50

# Declared on both routes so the exported contract carries the error shape
# the Laravel client has to handle, and so FastAPI stops advertising its
# own HTTPValidationError for a 422 these endpoints never emit.
_ERROR_RESPONSES: dict[int | str, dict[str, object]] = {
    401: {
        "model": ErrorResponse,
        "description": "INVALID_SIGNATURE - X-Signature missing or does not match the body",
    },
    422: {
        "model": ErrorResponse,
        "description": "VALIDATION_ERROR, or BATCH_TOO_LARGE when items exceeds "
        f"{_BATCH_MAX_ITEMS}",
    },
}


def get_analyzer() -> SentimentAnalyzer:
    """FastAPI dependency provider for the active analyzer.

    Returns the real local inference pipeline (ADR-0004), built once at
    start-up by app.analyzers.registry. Overridden in tests (via
    `app.dependency_overrides`) to inject fakes such as StubAnalyzer.
    """
    return get_pipeline()


def _validation_error(exc: ValidationError, correlation_id: str | None) -> ApiError:
    errors = exc.errors()
    is_batch_too_large = any(
        error["type"] == "too_long" and error["loc"][:1] == ("items",) for error in errors
    )
    if is_batch_too_large:
        return ApiError(
            status.HTTP_422_UNPROCESSABLE_CONTENT,
            "BATCH_TOO_LARGE",
            f"items must not contain more than {_BATCH_MAX_ITEMS} entries",
            correlation_id,
        )
    return ApiError(
        status.HTTP_422_UNPROCESSABLE_CONTENT,
        "VALIDATION_ERROR",
        str(exc),
        correlation_id,
    )


@router.post("/v1/analyze", response_model=AnalyzeResponse, responses=_ERROR_RESPONSES)
def analyze(
    verified: tuple[bytes, str] = Depends(verify_signature),
    analyzer: SentimentAnalyzer = Depends(get_analyzer),  # noqa: B008
) -> AnalyzeResponse:
    body, correlation_id = verified

    try:
        payload = AnalyzeRequest.model_validate_json(body)
    except ValidationError as exc:
        raise _validation_error(exc, correlation_id) from exc

    started_at = time.perf_counter()
    result = analyzer.analyze(payload.text, payload.language_hint)
    duration_ms = round((time.perf_counter() - started_at) * 1000, 2)

    logger.info(
        "analyze completed",
        extra={
            "correlation_id": correlation_id,
            "duration_ms": duration_ms,
            "category": result.category.value,
            "label": result.sentiment_label.value,
        },
    )

    return AnalyzeResponse(correlation_id=correlation_id, **result.model_dump())


@router.post("/v1/analyze/batch", response_model=BatchAnalyzeResponse, responses=_ERROR_RESPONSES)
def analyze_batch(
    verified: tuple[bytes, str] = Depends(verify_signature),
    analyzer: SentimentAnalyzer = Depends(get_analyzer),  # noqa: B008
) -> BatchAnalyzeResponse:
    body, correlation_id = verified

    try:
        payload = BatchAnalyzeRequest.model_validate_json(body)
    except ValidationError as exc:
        raise _validation_error(exc, correlation_id) from exc

    started_at = time.perf_counter()
    results: list[BatchResultItem] = []
    for item in payload.items:
        analysis = analyzer.analyze(item.text, item.language_hint)
        results.append(BatchResultItem(id=item.id, **analysis.model_dump()))
    duration_ms = round((time.perf_counter() - started_at) * 1000, 2)

    logger.info(
        "analyze batch completed",
        extra={
            "correlation_id": correlation_id,
            "duration_ms": duration_ms,
            "item_count": len(results),
        },
    )

    # Taken from the results rather than from a global, so the envelope's
    # model_version can never disagree with the items it wraps — including
    # when a test injects a different analyzer.
    return BatchAnalyzeResponse(
        results=results,
        model_version=results[0].model_version,
        correlation_id=correlation_id,
    )
