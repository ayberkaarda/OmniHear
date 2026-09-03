"""Application settings loaded from environment variables (.env)."""

from pathlib import Path

from pydantic import ValidationError, field_validator
from pydantic_settings import BaseSettings, SettingsConfigDict

_PACKAGE_ROOT = Path(__file__).resolve().parent

# Values that appear in checked-in example files. None of them is a secret.
_PUBLISHED_PLACEHOLDERS = {"REPLACE_ME", "CHANGE_ME", "CHANGEME", "SECRET"}


class Settings(BaseSettings):
    """AI service configuration.

    All values are sourced from environment variables. See .env.example
    for the full list of supported variables and their defaults.
    """

    model_config = SettingsConfigDict(env_prefix="", env_file=".env", extra="ignore")

    # Required, with no default on purpose (invariant I7).
    #
    # This field used to default to "REPLACE_ME" - the same literal printed in
    # .env.example, in a public repository. With no environment variable set,
    # the analyzer accepted any request signed with a string anybody could
    # read, which is indistinguishable from having no signature check at all.
    # A shared secret cannot have a default; it can only be supplied or absent,
    # and absent has to mean the service does not start.
    ai_service_hmac_secret: str

    log_level: str = "INFO"

    @field_validator("ai_service_hmac_secret")
    @classmethod
    def _reject_placeholder_secret(cls, value: str) -> str:
        """Refuse the placeholders that ship with the repository.

        Removing the default is the fix; this is the second lock, for the
        deployment that copies .env.example verbatim and never edits it. A
        published placeholder is a published secret.
        """
        if not value.strip():
            raise ValueError("must not be empty")
        if value.strip().upper() in _PUBLISHED_PLACEHOLDERS:
            raise ValueError(f"{value!r} is a placeholder published in this repository")
        return value

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


try:
    settings = Settings()
except ValidationError as exc:  # pragma: no cover - exercised by test_config.py
    raise RuntimeError(
        "AI_SERVICE_HMAC_SECRET is not configured, so the analyzer refuses to "
        "start. It is the shared secret of the Laravel <-> FastAPI contract "
        "(invariant I7): a default would be a secret published in this "
        "repository, and any caller could then sign a request this service "
        "accepts. Generate one with `openssl rand -hex 32` and set the same "
        "value on this service and on the backend."
    ) from exc
