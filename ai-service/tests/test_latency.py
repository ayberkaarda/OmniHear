"""Latency regression test for the p95 < 800 ms SLO (spec §6.3).

What is measured is the *server-side* cost of an analysis: the endpoint
handler through TestClient, which covers HMAC verification, JSON parsing,
the full pipeline and response serialisation. Network time and Laravel's
own queue latency are outside this service's budget and outside this
test.

Two properties keep it from being a flaky CI liability:

* **Warm-up requests are excluded.** The first call pays for lazily
  imported modules and, on the ONNX backend, the ORT session's first
  allocation. That cost is real but it is a start-up cost, not a
  per-request one — the registry builds the pipeline once at import.
* **The assertion threshold is the SLO itself**, not a tightened local
  number. A shared CI runner is slower than a workstation; failing at
  400 ms would produce noise, not signal. What this test catches is the
  order-of-magnitude regression — someone loading weights per request,
  or adding a network call to the request path.

The measured p95 on the development machine is recorded in MODEL_CARD.md.
"""

import json
import time

import pytest
from fastapi.testclient import TestClient

# Spec §6.3.
P95_BUDGET_MS = 800.0

# Enough samples for the 95th percentile to mean something without making
# the suite slow: with 60 samples the p95 is the 3rd-slowest request.
SAMPLE_COUNT = 60
WARMUP_COUNT = 5

SAMPLE_TEXTS = [
    "Uygulama sürekli çöküyor, para iadesi istiyorum. Berbat bir deneyim oldu.",
    "Absolutely love this app, the interface is clean and it is a joy to use.",
    "Please add a dark mode option and widget support in the next release.",
    "Aylık ücret çok pahalı ve destek ekibi bir haftadır dönüş yapmadı.",
    "The app crashes every time I open the camera and I lose all my data.",
    "Karanlık mod eklerseniz çok sevinirim, teşekkürler.",
]


def _percentile(values: list[float], fraction: float) -> float:
    """Nearest-rank percentile — no interpolation, no numpy dependency."""
    ordered = sorted(values)
    index = max(0, min(len(ordered) - 1, round(fraction * len(ordered)) - 1))
    return ordered[index]


def _measure(client: TestClient, make_headers, texts: list[str], count: int) -> list[float]:
    durations: list[float] = []
    for index in range(count):
        body = json.dumps({"text": texts[index % len(texts)]}).encode("utf-8")
        headers = make_headers(body)
        started = time.perf_counter()
        response = client.post("/v1/analyze", content=body, headers=headers)
        durations.append((time.perf_counter() - started) * 1000)
        assert response.status_code == 200
    return durations


@pytest.fixture(scope="module")
def latency_samples(request) -> list[float]:
    """Measure once; several assertions read the same sample set."""
    from tests.conftest import DEFAULT_CORRELATION_ID, sign

    def make_headers(body: bytes) -> dict[str, str]:
        return {"X-Correlation-Id": DEFAULT_CORRELATION_ID, "X-Signature": sign(body)}

    from app.main import app

    with TestClient(app) as client:
        _measure(client, make_headers, SAMPLE_TEXTS, WARMUP_COUNT)
        samples = _measure(client, make_headers, SAMPLE_TEXTS, SAMPLE_COUNT)

    print(
        f"\n/v1/analyze latency over {len(samples)} requests: "
        f"p50={_percentile(samples, 0.50):.1f} ms  "
        f"p95={_percentile(samples, 0.95):.1f} ms  "
        f"max={max(samples):.1f} ms"
    )
    return samples


def test_single_analyze_p95_is_within_the_slo(latency_samples: list[float]) -> None:
    p95 = _percentile(latency_samples, 0.95)
    assert p95 < P95_BUDGET_MS, f"p95 {p95:.1f} ms exceeds the {P95_BUDGET_MS:.0f} ms budget"


def test_no_single_request_is_wildly_out_of_band(latency_samples: list[float]) -> None:
    """A lone 3-second outlier is the signature of per-request model
    loading, which the p95 alone could hide."""
    worst = max(latency_samples)
    assert worst < 2 * P95_BUDGET_MS, f"slowest request took {worst:.1f} ms"


def test_batch_of_fifty_stays_within_a_proportional_budget(
    client: TestClient, make_headers
) -> None:
    """The batch endpoint has no SLO of its own in the spec. What it must
    not do is cost more per item than the single endpoint — that would
    mean per-item setup work leaking into the loop."""
    items = [
        {"id": str(index), "text": SAMPLE_TEXTS[index % len(SAMPLE_TEXTS)]} for index in range(50)
    ]
    body = json.dumps({"items": items}).encode("utf-8")
    headers = make_headers(body)

    client.post("/v1/analyze/batch", content=body, headers=headers)  # warm-up

    started = time.perf_counter()
    response = client.post("/v1/analyze/batch", content=body, headers=headers)
    elapsed_ms = (time.perf_counter() - started) * 1000

    assert response.status_code == 200
    print(f"\n/v1/analyze/batch 50 items: {elapsed_ms:.1f} ms ({elapsed_ms / 50:.1f} ms/item)")
    assert elapsed_ms < 50 * P95_BUDGET_MS
