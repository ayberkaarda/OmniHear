<?php

namespace App\Support\Connectors;

use InvalidArgumentException;

/**
 * One page of a connector run.
 *
 * A page, not an iterable, on purpose: an iterable cannot distinguish "the
 * stream ended" from "this page happened to come back empty", and it cannot
 * carry the platform's opaque cursor. The App Store feed returns empty pages
 * intermittently (PROGRESS, 2026-09-02), so that distinction is the difference
 * between a correct run and silent data loss.
 *
 * Semantics the runner relies on:
 *
 *  1. `$items === []` says nothing about the stream. Only `$hasMore` does.
 *  2. `$hasMore === true` implies `$nextCursor !== null` — asserted below.
 *  3. `$hasMore === false` ends the run, and `$nextCursor` is then the cursor
 *     the *next* run starts from (null means "leave the stored cursor alone").
 *  4. The cursor is opaque. Only the connector that produced it may read it.
 */
final readonly class ConnectorPage
{
    /**
     * @param  list<ConnectorItem>  $items
     * @param  string|null  $nextCursor  opaque, connector-owned
     * @param  string|null  $watermark  highest publishedAt on this page; null when the page is empty
     */
    public function __construct(
        public array $items,
        public bool $hasMore,
        public ?string $nextCursor,
        public ?string $watermark = null,
    ) {
        if ($hasMore && $nextCursor === null) {
            throw new InvalidArgumentException(
                'A connector page that reports hasMore must carry a nextCursor; '
                .'without one the run would restart from the beginning on every pass.'
            );
        }
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }
}
