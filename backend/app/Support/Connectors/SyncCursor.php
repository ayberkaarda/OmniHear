<?php

namespace App\Support\Connectors;

use Carbon\CarbonImmutable;
use JsonException;

/**
 * The opaque cursor both paged connectors encode into `integrations.sync_cursor`.
 *
 * Two values travel together because one alone is not enough:
 *
 *  - `page` is the intra-run pointer. It resets to 1 at the end of every run,
 *    since these feeds are sorted newest-first and the next run has to start at
 *    the top again to see what arrived since.
 *  - `watermark` is the inter-run pointer: the highest publishedAt already
 *    ingested. It is what makes the fetch incremental (spec 6.1) — a run stops
 *    as soon as it reaches an item at or below it, instead of walking the whole
 *    history again.
 *  - `token` is the platform's own opaque continuation value, for connectors
 *    whose incrementality is a cursor rather than a timestamp (Zendesk's
 *    incremental export answers an `after_cursor` and an `end_of_stream` flag).
 *    It is carried, never interpreted: only the connector that wrote it may
 *    read it. It has to live here rather than in a connector-private encoding
 *    because IngestionRunner decodes and re-encodes the cursor to promote the
 *    watermark, and anything this class does not know about would be dropped on
 *    that round trip.
 *  - `pending` is the watermark the *current* run has reached so far. It exists
 *    because these feeds are sorted newest-first: if the watermark advanced
 *    page by page, page 2 would be entirely at or below the value page 1 just
 *    wrote, `alreadySeen` would report true for every item on it, and a
 *    multi-page run would silently ingest only its first page. So `watermark`
 *    stays frozen for the whole run and `pending` is promoted onto it at the
 *    end, when `page` resets to 1.
 *
 * The column is varchar(255); this encoding stays well inside that.
 */
final readonly class SyncCursor
{
    public function __construct(
        public int $page = 1,
        public ?string $watermark = null,
        public ?string $pending = null,
        public ?string $token = null,
    ) {}

    /**
     * Never throws: an unreadable cursor means "start over", which is correct
     * and idempotent (invariant I2 turns the re-fetch into no new rows), and is
     * a far better outcome than a permanently failing integration.
     */
    public static function decode(?string $cursor): self
    {
        if ($cursor === null || trim($cursor) === '') {
            return new self;
        }

        try {
            $decoded = json_decode($cursor, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return new self;
        }

        if (! is_array($decoded)) {
            return new self;
        }

        $page = isset($decoded['page']) && is_numeric($decoded['page'])
            ? max(1, (int) $decoded['page'])
            : 1;

        $watermark = isset($decoded['watermark']) && is_string($decoded['watermark']) && $decoded['watermark'] !== ''
            ? $decoded['watermark']
            : null;

        $pending = isset($decoded['pending']) && is_string($decoded['pending']) && $decoded['pending'] !== ''
            ? $decoded['pending']
            : null;

        $token = isset($decoded['token']) && is_string($decoded['token']) && $decoded['token'] !== ''
            ? $decoded['token']
            : null;

        return new self($page, $watermark, $pending, $token);
    }

    public function encode(): string
    {
        return (string) json_encode(
            array_filter(
                [
                    'page' => $this->page,
                    'watermark' => $this->watermark,
                    'pending' => $this->pending,
                    'token' => $this->token,
                ],
                static fn ($value) => $value !== null,
            ),
            JSON_UNESCAPED_SLASHES,
        );
    }

    public function withPage(int $page): self
    {
        return new self($page, $this->watermark, $this->pending, $this->token);
    }

    /**
     * Carry the platform's own continuation value. Opaque here on purpose: this
     * class never parses it, it only makes sure the round trip through
     * IngestionRunner does not lose it.
     */
    public function withToken(?string $token): self
    {
        return new self($this->page, $this->watermark, $this->pending, $token);
    }

    /**
     * Record how far the current run has reached, without moving the watermark
     * `alreadySeen` compares against. Call this for every page; call
     * `promoted()` once, when the run ends.
     */
    public function pendingAdvancedTo(?string $candidate): self
    {
        $advanced = (new self($this->page, $this->pending))->advancedTo($candidate);

        return new self($this->page, $this->watermark, $advanced->watermark, $this->token);
    }

    /**
     * End of run: fold `pending` into `watermark` and rewind to page 1, so the
     * next run starts at the top of the feed and stops at everything this run
     * already ingested.
     */
    public function promoted(): self
    {
        return new self(1, $this->advancedTo($this->pending)->watermark, null, $this->token);
    }

    /**
     * Advance the watermark, keeping whichever timestamp is later. Unparseable
     * candidates are ignored rather than allowed to move the watermark to a
     * value nothing can be compared against.
     */
    public function advancedTo(?string $candidate): self
    {
        if ($candidate === null) {
            return $this;
        }

        $candidateAt = self::parse($candidate);

        if ($candidateAt === null) {
            return $this;
        }

        $currentAt = self::parse($this->watermark);

        if ($currentAt !== null && $currentAt->greaterThanOrEqualTo($candidateAt)) {
            return $this;
        }

        return new self($this->page, $candidate, $this->pending, $this->token);
    }

    /**
     * True when the item is at or below the watermark, i.e. already ingested by
     * an earlier run. Items with no timestamp are never treated as seen — the
     * unique index is the safety net for those, not this comparison.
     */
    public function alreadySeen(?string $publishedAt): bool
    {
        $watermarkAt = self::parse($this->watermark);

        if ($watermarkAt === null) {
            return false;
        }

        $publishedAtParsed = self::parse($publishedAt);

        if ($publishedAtParsed === null) {
            return false;
        }

        return $publishedAtParsed->lessThanOrEqualTo($watermarkAt);
    }

    public static function parse(?string $timestamp): ?CarbonImmutable
    {
        if ($timestamp === null || trim($timestamp) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($timestamp);
        } catch (\Throwable) {
            return null;
        }
    }
}
