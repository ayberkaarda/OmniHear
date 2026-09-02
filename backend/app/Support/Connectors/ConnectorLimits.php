<?php

namespace App\Support\Connectors;

/**
 * The two ceilings the ingestion runner enforces regardless of what the
 * connector claims about the stream.
 *
 * A connector that keeps answering hasMore=true — because of a platform bug, a
 * cursor that fails to advance, or a mistake in a future implementation — must
 * not turn into an unbounded loop against a third-party API.
 */
final readonly class ConnectorLimits
{
    public function __construct(
        public int $maxPagesPerRun,
        public int $maxConsecutiveEmptyPages,
    ) {}
}
