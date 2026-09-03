"""FastAPI application entrypoint: app instance, router registration,
and exception handlers that enforce the uniform error contract
{"code", "message", "correlation_id"}.
"""

from fastapi import FastAPI, Request, status
from fastapi.exceptions import RequestValidationError
from fastapi.responses import JSONResponse
from starlette.exceptions import HTTPException as StarletteHTTPException

from app.config import settings
from app.logging_config import configure_logging
from app.openapi import build_openapi
from app.routers import analyze, health

# JSON lines (spec 3.6), matching the backend's `json` log channel so a
# correlation_id can be searched across both services. See
# app.logging_config for the shape and app.routers.analyze's module
# docstring for what is (and is not) permitted into a log line.
configure_logging(settings.log_level)

# The OpenAPI export normalises info.version away (see
# scripts/export_openapi.py), so this string is documentation metadata
# only; the value that matters travels in every response's model_version.
app = FastAPI(title="OmniHear AI Service", version="1.0.0")

app.include_router(health.router)
app.include_router(analyze.router)

# Adds the manually-parsed request bodies to the schema; see app.openapi.
app.openapi = lambda: build_openapi(app)


@app.exception_handler(StarletteHTTPException)
async def http_exception_handler(request: Request, exc: StarletteHTTPException) -> JSONResponse:
    """Serialize HTTPException.detail as the response body.

    Every ApiError raised in this codebase (app.security, app.routers.*)
    already sets `detail` to a {"code", "message", "correlation_id"} dict,
    so this handler just passes it through. Any other HTTPException (e.g.
    a 404 for an unknown route) gets wrapped into the same shape.
    """
    if isinstance(exc.detail, dict) and "code" in exc.detail:
        content = exc.detail
    else:
        content = {"code": "HTTP_ERROR", "message": str(exc.detail), "correlation_id": None}
    return JSONResponse(status_code=exc.status_code, content=content)


@app.exception_handler(RequestValidationError)
async def validation_exception_handler(
    request: Request, exc: RequestValidationError
) -> JSONResponse:
    """Safety net for validation errors FastAPI raises before our own
    manual body parsing runs (e.g. malformed query params). Application
    request bodies are validated manually in app.routers.analyze so they
    raise ApiError(VALIDATION_ERROR/BATCH_TOO_LARGE) directly instead.
    """
    return JSONResponse(
        status_code=status.HTTP_422_UNPROCESSABLE_CONTENT,
        content={"code": "VALIDATION_ERROR", "message": str(exc), "correlation_id": None},
    )
