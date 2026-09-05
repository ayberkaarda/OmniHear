"""OpenAPI document assembly.

``app.routers.analyze`` reads and HMAC-verifies the raw request body
before parsing it (see that module's docstring), which means the request
bodies are *not* FastAPI body parameters — and so FastAPI cannot see them
when it builds the schema. Left alone, ``contracts/ai-openapi.json`` would
document two POST endpoints that take no body, and the Laravel side would
have to guess the request shape. CONTRIBUTING.md names that file as *the*
Laravel <-> FastAPI contract, so guessing is exactly what must not happen.

This module fills the gap **without hand-writing any field list**. The
request schemas are generated from the same Pydantic models the router
validates against, via ``models_json_schema``; only the wiring — which
model belongs to which path — is declared here, and a test asserts that
wiring is correct. A field added to ``AnalyzeRequest`` therefore appears
in the contract on the next export with no edit to this file.
"""

from typing import Any

from fastapi import FastAPI
from fastapi.openapi.utils import get_openapi
from pydantic.json_schema import models_json_schema

from app.schemas import AnalyzeRequest, BatchAnalyzeRequest, BatchItem

# Models whose schemas FastAPI cannot discover on its own.
_MANUAL_BODY_MODELS = (AnalyzeRequest, BatchAnalyzeRequest, BatchItem)

# path -> component schema name of its request body.
REQUEST_BODY_MODELS: dict[str, str] = {
    "/v1/analyze": "AnalyzeRequest",
    "/v1/analyze/batch": "BatchAnalyzeRequest",
}


def build_openapi(app: FastAPI) -> dict[str, Any]:
    """Return the full OpenAPI document, cached on the app instance."""
    if app.openapi_schema:
        return app.openapi_schema

    schema = get_openapi(
        title=app.title,
        version=app.version,
        description=app.description or None,
        routes=app.routes,
    )

    _, definitions = models_json_schema(
        [(model, "validation") for model in _MANUAL_BODY_MODELS],
        ref_template="#/components/schemas/{model}",
    )
    components = schema.setdefault("components", {}).setdefault("schemas", {})
    components.update(definitions["$defs"])

    for path, model_name in REQUEST_BODY_MODELS.items():
        schema["paths"][path]["post"]["requestBody"] = {
            "required": True,
            "content": {
                "application/json": {"schema": {"$ref": f"#/components/schemas/{model_name}"}}
            },
        }

    app.openapi_schema = schema
    return schema
