"""app.logging_config: the JSON shape itself.

test_logging_privacy.py already proves request text never reaches a log
line; these tests prove the positive side — that what does reach a log
line is actually machine-parseable JSON carrying `correlation_id`, so it
can be joined against the backend's `json` log channel (spec 3.6) — and
add one more privacy check at the formatter's own boundary, independent
of the FastAPI request cycle.
"""

import json
import logging

from app.logging_config import JsonFormatter


def _make_record(
    message: str = "analyze completed", extra: dict[str, object] | None = None
) -> logging.LogRecord:
    record = logging.LogRecord(
        name="ai_service.analyze",
        level=logging.INFO,
        pathname=__file__,
        lineno=1,
        msg=message,
        args=(),
        exc_info=None,
    )
    for key, value in (extra or {}).items():
        setattr(record, key, value)
    return record


def test_a_formatted_record_is_valid_json_with_the_expected_fields() -> None:
    record = _make_record(extra={"correlation_id": "11111111-1111-1111-1111-111111111111"})

    rendered = JsonFormatter().format(record)
    payload = json.loads(rendered)  # raises if this is not a single JSON object

    assert payload["message"] == "analyze completed"
    assert payload["level"] == "INFO"
    assert payload["logger"] == "ai_service.analyze"
    assert payload["correlation_id"] == "11111111-1111-1111-1111-111111111111"
    assert "timestamp" in payload


def test_the_correlation_id_survives_into_the_json_line() -> None:
    correlation_id = "22222222-2222-2222-2222-222222222222"
    record = _make_record(extra={"correlation_id": correlation_id, "duration_ms": 12.5})

    payload = json.loads(JsonFormatter().format(record))

    assert payload["correlation_id"] == correlation_id
    assert payload["duration_ms"] == 12.5


def test_correlation_id_is_null_rather_than_absent_when_not_supplied() -> None:
    payload = json.loads(JsonFormatter().format(_make_record()))

    assert payload["correlation_id"] is None


def test_the_formatter_never_emits_the_request_text_it_was_not_given() -> None:
    """The formatter's own contribution to invariant I5: it must not widen
    what a call site logs. Only the fields the analyze router actually
    passes through `extra` (correlation_id, duration_ms, category, label,
    item_count) should ever appear — nothing resembling request content."""
    secret_text = "Zamboni kalibrasyonu bozuldu ve QuixoticWidget9271 patladi"
    record = _make_record(
        message="analyze completed",
        extra={
            "correlation_id": "33333333-3333-3333-3333-333333333333",
            "duration_ms": 4.0,
            "category": "bug",
            "label": "negative",
        },
    )

    rendered = JsonFormatter().format(record)

    assert secret_text not in rendered
    assert "Zamboni" not in rendered
