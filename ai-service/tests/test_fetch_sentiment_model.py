"""Exercises the retry/resume machinery in scripts/fetch_sentiment_model.py.

CI run 33796748134 failed the E2E job because a bare ``urlretrieve`` had no
retry and no resume: a connection drop 40% into a 168 MB download killed the
whole image build. These tests run the same code paths against a small local
HTTP server that can truncate a response and drop the connection on demand,
so a transient failure and a mid-file resume are exercised for real rather
than only reasoned about.

None of this touches the network or the real Hugging Face URLs; ``FILES`` and
``BASE_URL`` are only used by the CLI-level tests, and those are pointed at
the local server too.
"""

from __future__ import annotations

import hashlib
import http.server
import socket
import threading

import pytest

import scripts.fetch_sentiment_model as fsm


class _State:
    def __init__(self, content: bytes) -> None:
        self.content = content
        self.remaining_failures = 0
        self.ignore_range = False
        self.force_status: int | None = None
        self.range_headers_seen: list[str] = []


def _make_handler(state: _State) -> type[http.server.BaseHTTPRequestHandler]:
    class Handler(http.server.BaseHTTPRequestHandler):
        protocol_version = "HTTP/1.1"

        def log_message(self, format: str, *args: object) -> None:  # noqa: A002
            pass

        def do_GET(self) -> None:  # noqa: N802
            state.range_headers_seen.append(self.headers.get("Range", ""))

            if state.force_status is not None:
                self.send_response(state.force_status)
                self.send_header("Content-Length", "0")
                self.end_headers()
                return

            body = state.content
            range_header = None if state.ignore_range else self.headers.get("Range")
            offset = 0
            status = 200
            if range_header:
                start = int(range_header.split("=", 1)[1].split("-", 1)[0])
                if start >= len(body):
                    self.send_response(416)
                    self.send_header("Content-Range", f"bytes */{len(body)}")
                    self.send_header("Content-Length", "0")
                    self.end_headers()
                    return
                offset = start
                status = 206
            to_send = body[offset:]

            if state.remaining_failures > 0:
                state.remaining_failures -= 1
                cut = to_send[: max(1, len(to_send) // 3)]
                self.send_response(status)
                if status == 206:
                    self.send_header("Content-Range", f"bytes {offset}-{len(body) - 1}/{len(body)}")
                # Declare the full remaining length, then send less and drop the
                # connection -- exactly the "got only N out of M bytes" shape.
                self.send_header("Content-Length", str(len(to_send)))
                self.end_headers()
                self.wfile.write(cut)
                self.wfile.flush()
                self.close_connection = True
                try:
                    self.connection.shutdown(socket.SHUT_RDWR)
                except OSError:
                    pass
                return

            self.send_response(status)
            if status == 206:
                self.send_header("Content-Range", f"bytes {offset}-{len(body) - 1}/{len(body)}")
            self.send_header("Content-Length", str(len(to_send)))
            self.end_headers()
            self.wfile.write(to_send)

    return Handler


@pytest.fixture
def http_server(monkeypatch):
    """Yields a factory: content bytes -> (base_url, state). Also silences retry sleeps."""
    monkeypatch.setattr(fsm.time, "sleep", lambda seconds: None)
    servers: list[http.server.HTTPServer] = []

    def _start(content: bytes) -> tuple[str, _State]:
        state = _State(content)
        httpd = http.server.HTTPServer(("127.0.0.1", 0), _make_handler(state))
        thread = threading.Thread(target=httpd.serve_forever, daemon=True)
        thread.start()
        servers.append(httpd)
        return f"http://127.0.0.1:{httpd.server_port}/weights.bin", state

    yield _start

    for httpd in servers:
        httpd.shutdown()
        httpd.server_close()


def _content(size: int) -> bytes:
    # Deterministic, non-repeating-at-short-period filler so a truncation
    # or a Range offset is trivially distinguishable byte-for-byte.
    return (hashlib.sha256(b"omnihear-fixture-seed").digest() * (size // 32 + 1))[:size]


# --- _fetch_once ---------------------------------------------------------------


def test_fresh_download_writes_full_content(tmp_path, http_server):
    content = _content(50_000)
    url, _state = http_server(content)
    partial = tmp_path / "weights.bin.part"

    written = fsm._fetch_once(url, partial, len(content))

    assert written == len(content)
    assert partial.read_bytes() == content


def test_resumes_from_an_existing_partial_file(tmp_path, http_server):
    """The exact scenario from CI run 33796748134: a partial file is already
    sitting at the target when the script runs. It must resume from that
    offset (via Range), not restart, and the finished file must hash correctly.
    """
    content = _content(200_000)
    url, state = http_server(content)
    partial = tmp_path / "weights.bin.part"
    truncated_at = 67_103  # arbitrary, mirrors the shape of the real incident
    partial.write_bytes(content[:truncated_at])

    written = fsm._fetch_once(url, partial, len(content))

    assert written == len(content)
    assert partial.read_bytes() == content
    assert hashlib.sha256(partial.read_bytes()).hexdigest() == hashlib.sha256(content).hexdigest()
    assert any(h == f"bytes={truncated_at}-" for h in state.range_headers_seen)


def test_server_ignoring_range_restarts_instead_of_corrupting(tmp_path, http_server):
    """If the server answers 200 to a Range request, appending would silently
    build a corrupt file (partial bytes + the full body glued on). It must
    discard the partial and start over instead.
    """
    content = _content(80_000)
    url, state = http_server(content)
    state.ignore_range = True
    partial = tmp_path / "weights.bin.part"
    partial.write_bytes(content[:30_000])

    written = fsm._fetch_once(url, partial, len(content))

    assert written == len(content)
    assert partial.read_bytes() == content  # not content[:30_000] + content


def test_range_not_satisfiable_discards_and_restarts(tmp_path, http_server):
    """A partial already at (or past) the expected length gets a 416; the
    correct response is to discard it and re-fetch from zero, not to fail.
    """
    content = _content(40_000)
    url, _state = http_server(content)
    partial = tmp_path / "weights.bin.part"
    partial.write_bytes(content)  # already "complete" locally, forces a 416

    written = fsm._fetch_once(url, partial, len(content))

    assert written == len(content)
    assert partial.read_bytes() == content


# --- _download_with_retries ------------------------------------------------


def test_retries_recover_from_a_dropped_connection(tmp_path, http_server, monkeypatch, capsys):
    # Small chunks so a mid-stream drop leaves genuinely partial bytes on disk,
    # not just an all-or-nothing single read.
    monkeypatch.setattr(fsm, "_CHUNK", 4096)
    content = _content(120_000)
    url, state = http_server(content)
    state.remaining_failures = 2  # first two attempts get truncated and dropped

    partial = tmp_path / "weights.bin.part"
    fsm._download_with_retries("weights.bin", url, partial, len(content))

    assert partial.read_bytes() == content
    out = capsys.readouterr().out
    assert "attempt 1/5" in out
    assert "attempt 2/5" in out
    assert "attempt 3/5" in out
    assert "retrying in" in out
    # By attempt 3 at least one request should have carried a non-empty Range,
    # i.e. it resumed rather than restarting from scratch every time.
    assert any(h.startswith("bytes=") and h != "bytes=0-" for h in state.range_headers_seen)


def test_exhausting_retries_raises(tmp_path, http_server, monkeypatch):
    monkeypatch.setattr(fsm, "_CHUNK", 4096)
    content = _content(40_000)
    url, state = http_server(content)
    state.remaining_failures = 999  # never succeeds

    partial = tmp_path / "weights.bin.part"
    with pytest.raises(RuntimeError, match="giving up after 5 attempts"):
        fsm._download_with_retries("weights.bin", url, partial, len(content))


def test_permanent_http_error_is_not_retried(tmp_path, http_server, capsys):
    content = _content(1_000)
    url, state = http_server(content)
    state.force_status = 404

    partial = tmp_path / "weights.bin.part"
    with pytest.raises(RuntimeError, match="permanent failure, not retrying"):
        fsm._download_with_retries("weights.bin", url, partial, len(content))

    out = capsys.readouterr().out
    assert "attempt 2/5" not in out  # a single attempt, no retry


# --- _fetch_file / download --------------------------------------------------


def test_fetch_file_full_happy_path(tmp_path, http_server, monkeypatch):
    content = _content(10_000)
    url, _state = http_server(content)
    sha = hashlib.sha256(content).hexdigest()

    monkeypatch.setattr(fsm, "BASE_URL", url.rsplit("/", 1)[0] + "/")
    remote_path = url.rsplit("/", 1)[1]

    fsm._fetch_file(
        "weights.bin",
        remote_path=remote_path,
        expected_sha=sha,
        expected_size=len(content),
        dest=tmp_path,
        force=False,
    )

    target = tmp_path / "weights.bin"
    assert target.is_file()
    assert target.read_bytes() == content
    assert not (tmp_path / "weights.bin.part").exists()


def test_fetch_file_rejects_a_hash_mismatch_and_leaves_no_target(
    tmp_path, http_server, monkeypatch
):
    content = _content(5_000)
    url, _state = http_server(content)
    wrong_sha = "0" * 64

    monkeypatch.setattr(fsm, "BASE_URL", url.rsplit("/", 1)[0] + "/")
    remote_path = url.rsplit("/", 1)[1]

    with pytest.raises(RuntimeError, match="verification failed"):
        fsm._fetch_file(
            "weights.bin",
            remote_path=remote_path,
            expected_sha=wrong_sha,
            expected_size=len(content),
            dest=tmp_path,
            force=False,
        )

    assert not (tmp_path / "weights.bin").exists()
    assert not (tmp_path / "weights.bin.part").exists()


def test_fetch_file_force_redownloads_over_an_existing_target(tmp_path, http_server, monkeypatch):
    old_content = b"stale content, wrong length even"
    new_content = _content(8_000)
    url, state = http_server(new_content)
    sha = hashlib.sha256(new_content).hexdigest()

    monkeypatch.setattr(fsm, "BASE_URL", url.rsplit("/", 1)[0] + "/")
    remote_path = url.rsplit("/", 1)[1]

    target = tmp_path / "weights.bin"
    target.write_bytes(old_content)
    (tmp_path / "weights.bin.part").write_bytes(b"stray leftover partial")

    fsm._fetch_file(
        "weights.bin",
        remote_path=remote_path,
        expected_sha=sha,
        expected_size=len(new_content),
        dest=tmp_path,
        force=True,
    )

    assert target.read_bytes() == new_content
    assert state.range_headers_seen == [""]  # one plain request, not a resume of the stray partial


def test_fetch_file_skips_an_already_present_target(tmp_path, http_server):
    url, state = http_server(_content(100))
    target = tmp_path / "weights.bin"
    target.write_bytes(b"already here")

    fsm._fetch_file(
        "weights.bin",
        remote_path="weights.bin",
        expected_sha="irrelevant",
        expected_size=13,
        dest=tmp_path,
        force=False,
    )

    assert target.read_bytes() == b"already here"
    assert state.range_headers_seen == []  # no network call at all


# --- CLI-level: --verify-only and --print-hashes touch no network -----------


def test_verify_only_makes_no_network_call(tmp_path, monkeypatch, capsys):
    def _boom(*args, **kwargs):  # pragma: no cover - only invoked on regression
        raise AssertionError("--verify-only must not touch the network")

    monkeypatch.setattr(fsm.urllib.request, "urlopen", _boom)
    monkeypatch.setattr(
        fsm,
        "FILES",
        {"weights.bin": ("weights.bin", "0" * 64, 10)},
    )

    monkeypatch.setattr(
        fsm.sys, "argv", ["fetch_sentiment_model.py", "--dest", str(tmp_path), "--verify-only"]
    )
    exit_code = fsm.main()

    assert exit_code == 1  # the file is missing, but no network was attempted
    err = capsys.readouterr().err
    assert "missing" in err


def test_print_hashes_reports_present_and_missing(tmp_path, monkeypatch, capsys):
    present = tmp_path / "present.bin"
    present.write_bytes(b"hello world")
    monkeypatch.setattr(
        fsm,
        "FILES",
        {
            "present.bin": ("present.bin", "irrelevant", 11),
            "missing.bin": ("missing.bin", "irrelevant", 5),
        },
    )
    monkeypatch.setattr(
        fsm.sys, "argv", ["fetch_sentiment_model.py", "--dest", str(tmp_path), "--print-hashes"]
    )

    exit_code = fsm.main()

    assert exit_code == 0
    out = capsys.readouterr().out
    assert f"present.bin: {hashlib.sha256(b'hello world').hexdigest()}" in out
    assert "missing.bin: MISSING" in out
