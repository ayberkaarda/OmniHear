"""Application settings loaded from environment variables (.env)."""

from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    """AI service configuration.

    All values are sourced from environment variables. See .env.example
    for the full list of supported variables and their defaults.
    """

    model_config = SettingsConfigDict(env_prefix="", env_file=".env", extra="ignore")

    ai_service_hmac_secret: str = "REPLACE_ME"
    model_version: str = "stub-0.1.0"
    log_level: str = "INFO"


settings = Settings()
