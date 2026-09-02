"""Application settings loaded from environment variables (.env)."""

from pathlib import Path

from pydantic_settings import BaseSettings, SettingsConfigDict

_PACKAGE_ROOT = Path(__file__).resolve().parent


class Settings(BaseSettings):
    """AI service configuration.

    All values are sourced from environment variables. See .env.example
    for the full list of supported variables and their defaults.
    """

    model_config = SettingsConfigDict(env_prefix="", env_file=".env", extra="ignore")

    ai_service_hmac_secret: str = "REPLACE_ME"
    log_level: str = "INFO"

    # Sentiment backend selection: "auto" | "onnx" | "lexicon".
    # "auto" uses ONNX when its weights and optional dependencies are
    # present and falls back to the lexicon backend otherwise, logging a
    # warning. The container image pins "onnx" so a missing weights layer
    # fails the build rather than degrading silently in production.
    sentiment_backend: str = "auto"

    # Directory holding model_int8.onnx / tokenizer.json / config.json.
    # Populated at image build time by scripts/fetch_sentiment_model.py;
    # never fetched at runtime (ADR-0004: weights are a build artifact).
    sentiment_model_dir: Path = _PACKAGE_ROOT.parent / "models" / "sentiment"

    # ONNX Runtime intra-op thread count. Kept at 1 so that p95 does not
    # depend on how many requests happen to overlap on the same core set;
    # uvicorn already provides the concurrency.
    sentiment_intra_op_threads: int = 1

    # Escape hatch for pinning a specific model_version string, e.g. while
    # reproducing an old analysis during an incident. Leave unset in normal
    # operation: the computed value is the one that carries meaning.
    # (Named without a "model_" prefix so it does not collide with
    # Pydantic's protected namespace.)
    pinned_model_version: str | None = None


settings = Settings()
