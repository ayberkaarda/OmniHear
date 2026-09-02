"""Pydantic v2 request/response models for the AI service API contract."""

from enum import StrEnum

from pydantic import BaseModel, Field


class SentimentLabel(StrEnum):
    POSITIVE = "positive"
    NEUTRAL = "neutral"
    NEGATIVE = "negative"


class Category(StrEnum):
    COMPLAINT = "complaint"
    PRAISE = "praise"
    BUG = "bug"
    FEATURE_REQUEST = "feature_request"


class AnalyzeRequest(BaseModel):
    text: str = Field(min_length=1, max_length=10000)
    language_hint: str | None = None


class BatchItem(BaseModel):
    id: str
    text: str = Field(min_length=1, max_length=10000)
    language_hint: str | None = None


class BatchAnalyzeRequest(BaseModel):
    items: list[BatchItem] = Field(min_length=1, max_length=50)


class AnalysisResult(BaseModel):
    """Core analysis fields, shared by single and batch analyze responses."""

    sentiment_score: float = Field(ge=-1.0, le=1.0)
    sentiment_label: SentimentLabel
    category: Category
    confidence: float = Field(ge=0.0, le=1.0)
    keywords: list[str] = Field(max_length=10)
    language: str = Field(min_length=2, max_length=2)
    model_version: str


class AnalyzeResponse(AnalysisResult):
    correlation_id: str


class BatchResultItem(AnalysisResult):
    id: str


class BatchAnalyzeResponse(BaseModel):
    results: list[BatchResultItem]
    model_version: str
    correlation_id: str


class ErrorResponse(BaseModel):
    code: str
    message: str
    correlation_id: str | None = None
