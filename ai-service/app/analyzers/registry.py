"""Process-wide construction of the active analyzer.

One module owns the "build it once, at start-up" step so that the router,
the health endpoint and the OpenAPI exporter all observe the same
instance and the same ``model_version``. Construction is intentionally
eager at import time rather than lazy on first request: loading 168 MB of
weights inside the first HTTP request would blow the p95 SLO for whoever
happened to arrive first, and a missing-weights misconfiguration should
surface when the container starts, not an hour later.

The instance is immutable and its ``analyze`` method is side-effect free,
so sharing it across requests and threads is safe and keeps invariant I6
(stateless service) intact.
"""

import logging
from functools import lru_cache

from app.analyzers.category import CategoryClassifier
from app.analyzers.pipeline import PipelineAnalyzer, build_sentiment_backend
from app.config import settings

logger = logging.getLogger("ai_service.registry")


@lru_cache(maxsize=1)
def get_pipeline() -> PipelineAnalyzer:
    """Build (once) and return the process-wide pipeline analyzer."""
    backend = build_sentiment_backend(
        requested=settings.sentiment_backend,
        model_dir=settings.sentiment_model_dir,
        intra_op_threads=settings.sentiment_intra_op_threads,
    )
    pipeline = PipelineAnalyzer(
        backend,
        CategoryClassifier(),
        model_version_override=settings.pinned_model_version,
    )

    logger.info(
        "analysis pipeline ready",
        extra={
            "sentiment_backend": pipeline.sentiment_backend_id,
            "model_version": pipeline.model_version,
        },
    )
    return pipeline


def get_model_version() -> str:
    """The ``model_version`` every response carries.

    Single source of truth: the pipeline computes it (honouring
    ``PINNED_MODEL_VERSION`` when set), and both ``/health`` and the batch
    envelope read it from here, so the value in an item can never disagree
    with the value beside it.
    """
    return get_pipeline().model_version
