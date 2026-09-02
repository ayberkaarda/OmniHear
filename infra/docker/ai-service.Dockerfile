# OmniHear AI service — Python 3.12 (spec §2: fixed constraint).
#
# The host machine may carry a different Python; this image is the
# authoritative runtime. Verified: the F1 test suite passes on 3.12.14.
#
# Multi-stage: dependencies resolve in the builder, only the virtualenv
# and application source reach the runtime layer. Model weights added in
# F3 are baked in at build time — the service performs no network fetch
# at runtime (it is stateless and must start with no external I/O).

# ---------- builder ----------
FROM python:3.12-slim AS builder

ENV PYTHONDONTWRITEBYTECODE=1 \
    PIP_NO_CACHE_DIR=1 \
    PIP_DISABLE_PIP_VERSION_CHECK=1

WORKDIR /build

RUN python -m venv /opt/venv
ENV PATH="/opt/venv/bin:$PATH"

# Dependency metadata first so the layer caches across source edits.
COPY pyproject.toml ./
COPY app ./app
RUN pip install --no-cache-dir ".[onnx]"

# Model weights are a build artifact (ADR-0004), downloaded here and pinned by
# SHA-256 — never fetched at run time, which is what keeps the service stateless.
# Measured: ~171 MB, ~8 s on a warm network; the image lands near 480 MB, under
# the 1 GB target in the ADR.
COPY scripts ./scripts
RUN python -m scripts.fetch_sentiment_model --dest /opt/models/sentiment

# ---------- runtime ----------
FROM python:3.12-slim AS runtime

# SENTIMENT_BACKEND is pinned to onnx rather than left on `auto` on purpose: if the
# weights layer is ever broken, the service must fail loudly at start-up instead of
# silently degrading to the lexicon backend, which is far weaker (91.7% vs 36.7%
# accuracy on the evaluation set — see ai-service/MODEL_CARD.md).
ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    PATH="/opt/venv/bin:$PATH" \
    SENTIMENT_MODEL_DIR=/opt/models/sentiment \
    SENTIMENT_BACKEND=onnx

# Unprivileged user: nothing in this service needs root.
RUN groupadd --system --gid 1001 omnihear \
 && useradd --system --uid 1001 --gid omnihear --create-home omnihear

WORKDIR /srv/ai-service

COPY --from=builder /opt/venv /opt/venv
COPY --from=builder --chown=omnihear:omnihear /opt/models /opt/models
COPY --chown=omnihear:omnihear app ./app
COPY --chown=omnihear:omnihear pyproject.toml ./

USER omnihear

EXPOSE 8001

HEALTHCHECK --interval=15s --timeout=3s --start-period=20s --retries=3 \
    CMD python -c "import urllib.request,sys; sys.exit(0 if urllib.request.urlopen('http://127.0.0.1:8001/health', timeout=2).status == 200 else 1)"

CMD ["uvicorn", "app.main:app", "--host", "0.0.0.0", "--port", "8001"]
