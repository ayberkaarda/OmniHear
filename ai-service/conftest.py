"""Root conftest: the environment the test run needs before anything imports app.

``app.config`` has no default for ``AI_SERVICE_HMAC_SECRET`` on purpose - a
shared secret with a default is a secret published in this repository, and the
analyzer refuses to start without a real one (invariant I7). That means the test
process has to supply one, and it has to be in place *before* the first import
of ``app.config``, which happens while pytest is collecting ``tests/conftest.py``.
A rootdir conftest is loaded ahead of that, so this is the only file that runs
early enough.

``setdefault``, not assignment: a run inside the compose stack already has the
stack's secret in its environment, and the live-analyzer tests need to keep
using it rather than a value invented here.
"""

import os

os.environ.setdefault("AI_SERVICE_HMAC_SECRET", "pytest-hmac-secret-not-a-real-secret")
