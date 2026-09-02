<?php

namespace App\Support\Connectors;

use JsonException;

/**
 * A connector backed by JSON files in the repository.
 *
 * It exists so the whole ingestion pipeline — job, upsert, cursor, event — can
 * be exercised and demonstrated without a single credential and without a
 * network call. Every ingestion test runs against it, which is what keeps those
 * tests about the pipeline rather than about a third party's uptime.
 *
 * `platform = 'fixture'` is a first-class value in the schema for exactly this
 * reason (backend-core.md section 1) and never appears in the UI.
 *
 * One file is one page, ordered by filename, newest first — the same shape as
 * the real paged feeds, so the cursor semantics under test are the real ones.
 */
final readonly class FixtureConnector implements PlatformConnector
{
    public function __construct(
        private string $directory,
        private ConnectorLimits $limits,
    ) {}

    public function fetchPage(?string $cursor): ConnectorPage
    {
        $files = $this->files();
        $cursorState = SyncCursor::decode($cursor);
        $page = $cursorState->page;

        $entries = $page <= count($files) ? $this->read($files[$page - 1]) : [];

        $pageWatermark = null;
        $caughtUp = false;
        $items = [];

        foreach ($entries as $entry) {
            $publishedAt = isset($entry['published_at']) && is_string($entry['published_at'])
                ? $entry['published_at']
                : null;

            $pageWatermark = (new SyncCursor(watermark: $pageWatermark))->advancedTo($publishedAt)->watermark;

            if ($cursorState->alreadySeen($publishedAt)) {
                $caughtUp = true;

                continue;
            }

            $item = $this->toItem($entry, $publishedAt);

            if ($item !== null) {
                $items[] = $item;
            }
        }

        $reached = $cursorState->pendingAdvancedTo($pageWatermark);
        $hasMore = ! $caughtUp && $page < count($files);

        // Mid-run the watermark is left exactly where the run started and only
        // `pending` moves; promoting it here would make every older page look
        // already-seen and cut the run short after page 1.
        // Promotion is the runner's call, not this method's: only the runner
        // knows whether an earlier page of the same run came back empty, and
        // promoting after a skipped page buries its items below the watermark
        // forever. Here the cursor only advances `pending` and the page number.
        $nextCursor = $reached->withPage($hasMore ? $page + 1 : 1);

        return new ConnectorPage(
            items: $items,
            hasMore: $hasMore,
            nextCursor: $nextCursor->encode(),
            watermark: $pageWatermark,
        );
    }

    public function limits(): ConnectorLimits
    {
        return $this->limits;
    }

    public function healthCheck(): ConnectorHealth
    {
        return is_dir($this->directory)
            ? ConnectorHealth::ok()
            : ConnectorHealth::failing(ConnectorFailure::Misconfigured);
    }

    /**
     * @return list<string>
     */
    private function files(): array
    {
        if (! is_dir($this->directory)) {
            throw ConnectorException::of(ConnectorFailure::Misconfigured);
        }

        $files = glob(rtrim($this->directory, '/\\').DIRECTORY_SEPARATOR.'*.json') ?: [];

        sort($files);

        return array_values($files);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function read(string $file): array
    {
        $contents = @file_get_contents($file);

        if ($contents === false) {
            throw ConnectorException::of(ConnectorFailure::Misconfigured);
        }

        try {
            $decoded = json_decode($contents, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw ConnectorException::of(ConnectorFailure::MalformedResponse, $e);
        }

        if (! is_array($decoded) || ! array_is_list($decoded)) {
            throw ConnectorException::of(ConnectorFailure::MalformedResponse);
        }

        return array_values(array_filter($decoded, is_array(...)));
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function toItem(array $entry, ?string $publishedAt): ?ConnectorItem
    {
        $externalId = $entry['external_id'] ?? null;

        if (! is_string($externalId) && ! is_int($externalId)) {
            return null;
        }

        return new ConnectorItem(
            externalId: (string) $externalId,
            author: is_string($entry['author'] ?? null) ? $entry['author'] : null,
            body: is_string($entry['body'] ?? null) ? $entry['body'] : '',
            sourceUrl: is_string($entry['source_url'] ?? null) ? $entry['source_url'] : null,
            publishedAt: $publishedAt,
            rating: is_numeric($entry['rating'] ?? null) ? (int) $entry['rating'] : null,
            rawPayload: $entry,
        );
    }
}
