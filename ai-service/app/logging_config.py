"""Structured JSON logging for the AI service (spec 3.6).

`config/logging.php`'s `json` channel is the backend half of this: one
Monolog-formatted JSON object per line, with `correlation_id` living in
the context Laravel shares across every log call for a request. Before
this module the analyzer configured its root logger with `basicConfig`,
which renders unstructured lines, so a correlation id present on both
sides of one request could not be used to join the two halves in a log
search -- the entire reason the id is carried across the HTTP call at
all (spec 3.6, invariant I7's neighbour).

This module makes the analyzer's side JSON lines too: `timestamp`,
`level`, `message`, `logger`, `correlation_id`, plus whatever else the
call site passed through `extra=`.

**Discipline this must not weaken** (see app.routers.analyze's module
docstring): `feedbacks.body` is PII under KVKK and invariant I5 forbids
logging it. `routers/analyze.py` already logs only `correlation_id`,
`duration_ms`, and the resulting `label`/`category` -- this formatter
does not add anything to that; it only changes how those same fields are
rendered. It never captures positional `%s`-style message arguments
beyond the formatted `message` string, and it never guesses at "useful"
fields to include.
"""

import json
import logging
from datetime import UTC, datetime

# Every attribute a bare LogRecord carries before any `extra=` is merged
# in. Diffed against `record.__dict__` so that ONLY caller-supplied
# `extra` fields (such as `correlation_id`, `duration_ms`, `category`)
# are surfaced -- nothing from the stdlib's own record shape leaks in
# under a name it didn't already get an explicit slot for below.
_STANDARD_RECORD_KEYS = frozenset(logging.LogRecord("", 0, "", 0, "", (), None).__dict__)


class JsonFormatter(logging.Formatter):
    """Renders one JSON object per line.

    Fixed fields: `timestamp` (UTC, ISO 8601), `level`, `message`,
    `logger`, `correlation_id` (`None` when the call site did not supply
    one). Any other `extra=` field the call site attached is included
    verbatim. `default=str` covers values that are not natively
    JSON-serialisable (e.g. an enum) without raising out of the logging
    call itself.
    """

    def format(self, record: logging.LogRecord) -> str:
        payload: dict[str, object] = {
            "timestamp": datetime.fromtimestamp(record.created, tz=UTC).isoformat(),
            "level": record.levelname,
            "message": record.getMessage(),
            "logger": record.name,
            "correlation_id": getattr(record, "correlation_id", None),
        }
        for key, value in record.__dict__.items():
            if key not in _STANDARD_RECORD_KEYS and key != "correlation_id":
                payload[key] = value
        if record.exc_info:
            payload["exc_info"] = self.formatException(record.exc_info)
        return json.dumps(payload, default=str)


def configure_logging(level: str | int = "INFO") -> None:
    """Point the root logger at one stream handler emitting `JsonFormatter` lines.

    Idempotent -- safe to call more than once (app.main calls it at
    import time; a test may call it again) because it clears any
    handlers already attached to the root logger first, so records are
    never emitted twice down two handlers.
    """
    root = logging.getLogger()
    root.handlers.clear()
    handler = logging.StreamHandler()
    handler.setFormatter(JsonFormatter())
    root.addHandler(handler)
    root.setLevel(level)
