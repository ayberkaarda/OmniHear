"""HMAC-SHA256 signature verification for inbound requests.

The raw request body is read and verified against ``X-Signature`` BEFORE
any JSON parsing takes place, so an unauthenticated caller can never get
attacker-controlled bytes into the Pydantic layer.
"""

import hashlib
import hmac

from fastapi import Header, HTTPException, Request, status

from app.config import settings


class ApiError(HTTPException):
    """HTTPException whose `detail` already matches the API error contract.

    `main.py` registers a handler that serializes `detail` verbatim as the
    JSON body, so every raise site here and in the routers produces a
    response shaped as {"code", "message", "correlation_id"}.
    """

    def __init__(
        self, status_code: int, code: str, message: str, correlation_id: str | None
    ) -> None:
        super().__init__(
            status_code=status_code,
            detail={"code": code, "message": message, "correlation_id": correlation_id},
        )


async def verify_signature(
    request: Request,
    x_correlation_id: str | None = Header(default=None, alias="X-Correlation-Id"),
    x_signature: str | None = Header(default=None, alias="X-Signature"),
) -> tuple[bytes, str]:
    """Verify the request signature and return (raw_body, correlation_id).

    Rejects with 401 INVALID_SIGNATURE when the signature header is absent
    or does not match, and with 422 VALIDATION_ERROR when the mandatory
    correlation id header is absent. The body is returned as raw bytes so
    the caller can perform its own (post-verification) JSON parsing.
    """
    if not x_signature:
        raise ApiError(
            status.HTTP_401_UNAUTHORIZED,
            "INVALID_SIGNATURE",
            "X-Signature header is missing",
            x_correlation_id,
        )

    body = await request.body()
    expected_signature = hmac.new(
        settings.ai_service_hmac_secret.encode("utf-8"), body, hashlib.sha256
    ).hexdigest()

    if not hmac.compare_digest(expected_signature, x_signature):
        raise ApiError(
            status.HTTP_401_UNAUTHORIZED,
            "INVALID_SIGNATURE",
            "X-Signature does not match the request body",
            x_correlation_id,
        )

    if not x_correlation_id:
        raise ApiError(
            status.HTTP_422_UNPROCESSABLE_CONTENT,
            "VALIDATION_ERROR",
            "X-Correlation-Id header is required",
            None,
        )

    return body, x_correlation_id
