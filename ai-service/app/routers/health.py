"""Health check endpoint."""

from fastapi import APIRouter

from app.analyzers.registry import get_model_version, get_pipeline

router = APIRouter()


@router.get("/health")
def health() -> dict[str, str]:
    """Liveness plus the two facts an operator needs to identify a build.

    `sentiment_backend` is surfaced because the ONNX and lexicon backends
    are not equivalent in quality (see app.analyzers.sentiment): a
    container that silently came up on the fallback is a real incident,
    and this is where it becomes visible.
    """
    return {
        "status": "ok",
        "service": "ai-service",
        "model_version": get_model_version(),
        "sentiment_backend": get_pipeline().sentiment_backend_id,
    }
