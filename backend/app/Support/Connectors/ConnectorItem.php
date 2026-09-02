<?php

namespace App\Support\Connectors;

/**
 * One piece of feedback as a connector saw it, before it becomes a row.
 *
 * Deliberately flat and platform-agnostic: the ingestion runner maps these onto
 * `feedbacks` without knowing which platform produced them, so adding a
 * connector never means touching the persistence path.
 */
final readonly class ConnectorItem
{
    /**
     * @param  array<string, mixed>  $rawPayload
     */
    public function __construct(
        public string $externalId,
        public ?string $author,
        public string $body,
        public ?string $sourceUrl,
        public ?string $publishedAt,
        public ?int $rating,
        public array $rawPayload,
    ) {}
}
