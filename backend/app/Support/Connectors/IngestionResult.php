<?php

namespace App\Support\Connectors;

/**
 * What one ingestion run did. Counts only — never item content, so it is safe
 * to log and safe to return from an endpoint.
 */
final readonly class IngestionResult
{
    public function __construct(
        public int $pagesFetched,
        public int $itemsSeen,
        public int $created,
        public bool $capped,
        public ?string $cursor,
    ) {}
}
