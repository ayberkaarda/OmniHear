<?php

namespace App\Support\Connectors;

use App\Events\FeedbackIngested;
use App\Models\Feedback;
use App\Models\Integration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The page loop: fetch, persist, advance, stop.
 *
 * It lives outside FetchFeedbackJob so the loop can be tested against a fake
 * connector without a queue, and so the job is left with only the concerns a
 * job has — locking, throttling, retries and error state.
 *
 * Two ordering rules matter and are easy to get backwards:
 *
 *  - **Rows are written per page, the cursor only at the end of the run.** If
 *    page 7 fails, pages 1-6 are already saved and the cursor still points at
 *    the old position. The next run re-fetches them and the unique index turns
 *    that into zero new rows (invariant I2). Nothing is lost and nothing is
 *    duplicated. The reverse order would lose pages 1-6 or skip them forever.
 *  - **FeedbackIngested fires only for rows this run actually created.** The
 *    insert uses ON CONFLICT DO NOTHING ... RETURNING id, so "created" is
 *    decided by the database, not by a read-then-write guess that a concurrent
 *    run could invalidate. Firing on a re-fetch would re-analyse a comment and
 *    burn a second unit of quota — exactly what I2 exists to prevent.
 */
class IngestionRunner
{
    public function __construct(private readonly ConnectorFactory $connectors) {}

    /**
     * @throws ConnectorException
     */
    public function run(Integration $integration): IngestionResult
    {
        $connector = $this->connectors->for($integration);
        $limits = $connector->limits();

        $cursor = $integration->sync_cursor;
        $pages = 0;
        $emptyStreak = 0;
        $sawEmptyPage = false;
        $itemsSeen = 0;
        $created = 0;
        $capped = false;

        do {
            $page = $connector->fetchPage($cursor);
            $pages++;

            if ($page->isEmpty()) {
                // An empty page says nothing about the stream: the App Store
                // feed returns them intermittently (PROGRESS, 2026-09-02).
                // Only the streak is counted; stopping is hasMore's decision.
                $emptyStreak++;
                $sawEmptyPage = true;
            } else {
                $emptyStreak = 0;
                $itemsSeen += count($page->items);
                $created += $this->persist($integration, $page->items);
            }

            $cursor = $page->nextCursor ?? $cursor;

            $capped = $pages >= $limits->maxPagesPerRun
                || $emptyStreak >= $limits->maxConsecutiveEmptyPages;
        } while ($page->hasMore && ! $capped);

        if ($capped && $page->hasMore) {
            $this->logCapped($integration, $pages, $emptyStreak);
        }

        // The watermark is a high-water mark on published_at, so promoting it
        // buries everything older than the newest item this run saw. That is
        // only safe when the run actually reached the end of the stream: if a
        // page came back empty, or the page cap cut the run short, the items
        // that were missed are older than the new watermark and alreadySeen()
        // would reject them on every future run — erased, not retried.
        //
        // This feed returns empty pages intermittently (PROGRESS, 2026-09-02),
        // so it is not a hypothetical. An incomplete run keeps the old
        // watermark and the next one walks the same ground again; invariant I2
        // turns the re-fetch into no new rows, which is exactly what the unique
        // index is for.
        // `$capped` alone is not the test: a connector that reports hasMore=false
        // exactly as it reaches its own ceiling (the App Store feed caps page
        // depth at 10 and has nothing beyond it) trips the runner's cap in the
        // same iteration, and that run is complete. What must block promotion is
        // the runner cutting a run short *while the connector still had more* —
        // then the cursor's page number is what resumes it, and the watermark
        // has to stay where it was or the unreached pages fall below it.
        $reached = SyncCursor::decode($cursor);
        $complete = ! $page->hasMore && ! $sawEmptyPage;

        $cursor = ($complete ? $reached->promoted() : $reached)->encode();

        $attributes = [
            'sync_cursor' => $cursor,
            'last_synced_at' => now(),
            'sync_error' => null,
        ];

        // A successful run clears a previous failure, but it does not un-pause a
        // paused integration: pausing is a decision the user made, and only the
        // user reverses it.
        if ($integration->status === 'error') {
            $attributes['status'] = 'active';
        }

        $integration->forceFill($attributes)->save();

        return new IngestionResult($pages, $itemsSeen, $created, $capped, $cursor);
    }

    /**
     * Writes the page and returns how many rows were genuinely new.
     *
     * @param  list<ConnectorItem>  $items
     */
    private function persist(Integration $integration, array $items): int
    {
        $companyId = (int) $integration->company_id;
        $integrationId = (int) $integration->id;
        $now = now();

        $rows = [];

        foreach ($items as $item) {
            // Keyed by external id so a page that repeats one cannot send the
            // same conflict target twice in a single statement.
            $rows[$item->externalId] = [
                $companyId,
                $integrationId,
                $this->clamp($item->externalId),
                // Masked as well as clamped. `author` is a display name, and
                // on an e-mail channel the display name is routinely the
                // address itself.
                $item->author === null ? null : $this->maskPii($this->clamp($item->author)),
                $this->maskPii($item->body),
                $item->sourceUrl,
                // toIso8601String, not toDateTimeString: the latter drops the
                // offset, so App Store's "2026-08-27T23:40:59-07:00" reached the
                // timestamptz column as 23:40:59 UTC — the right wall clock in
                // the wrong zone, seven hours off the actual instant. Silent, and
                // it would have skewed every trend chart built on published_at.
                SyncCursor::parse($item->publishedAt)?->toIso8601String(),
                // Masked over the encoded JSON rather than by walking the
                // structure: every provider buries addresses somewhere
                // different (Zendesk's is via.source.from.address, and the
                // ticket body quotes more), so a field list would be a
                // per-provider allow list that is wrong the day a provider
                // adds a field. One pass over the text is provider-agnostic
                // and cannot miss a nesting level. `[email]` contains no
                // quote, backslash or brace, so the document stays valid JSON.
                $this->maskPii((string) json_encode($item->rawPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
                Feedback::STATUS_PENDING,
                $now,
                $now,
            ];
        }

        if ($rows === []) {
            return 0;
        }

        $ids = $this->insertIgnoringConflicts(array_values($rows));

        foreach ($ids as $id) {
            FeedbackIngested::dispatch($companyId, $id);
        }

        return count($ids);
    }

    /**
     * @param  list<list<mixed>>  $rows
     * @return list<int> ids of the rows this statement actually created
     */
    private function insertIgnoringConflicts(array $rows): array
    {
        $columns = [
            'company_id', 'integration_id', 'external_id', 'author', 'body',
            'source_url', 'published_at', 'raw_payload', 'analysis_status',
            'created_at', 'updated_at',
        ];

        $tuple = '('.implode(', ', array_fill(0, count($columns), '?')).')';

        $sql = 'insert into feedbacks ('.implode(', ', $columns).') values '
            .implode(', ', array_fill(0, count($rows), $tuple))
            .' on conflict (integration_id, external_id) do nothing returning id';

        // The statement has to be raw: ON CONFLICT DO NOTHING ... RETURNING id
        // is the only way to learn which rows were genuinely new without a
        // read-then-write race that would fire FeedbackIngested for a row a
        // concurrent run created. company_id is bound explicitly on every row.
        $inserted = DB::select($sql, array_merge(...$rows)); // tenant-scope: bypass-ok company_id bound explicitly per row from the integration

        return array_map(static fn (object $row): int => (int) $row->id, $inserted);
    }

    /**
     * Redact the obvious direct identifier before it is stored. Feedback bodies
     * are KVKK-protected personal data (spec 8) and reviewers routinely paste
     * their own address in asking for a reply.
     *
     * Applied to `body`, to `author` and to the encoded `raw_payload`. The last
     * one is the reason this comment is longer than the method: `raw_payload`
     * stores what the provider sent, whole, and for Zendesk that is the entire
     * ticket — including `via.source.from.address`, the requester's real
     * address. Nothing serializes the column (FeedbackResource omits it) and
     * erasure cascades, so this was never a disclosure; it was retention of a
     * direct identifier the product has no use for, which spec 8 requires to be
     * maskable.
     *
     * **The trade-off is deliberate and it is one-way.** After this,
     * `raw_payload` is no longer a byte-faithful archive of the provider
     * response: an address that arrives masked can never be recovered, and a
     * future feature that wants to reply to the author would need to re-fetch
     * from the provider rather than read the column. That is the correct
     * direction for a column whose only current purpose is debugging a mapping,
     * and the alternative — keeping the identifier so it might be useful later
     * — is exactly the retention KVKK is about.
     */
    private function maskPii(string $body): string
    {
        return (string) preg_replace('/[\w.+-]+@[\w-]+\.[a-z]{2,}/i', '[email]', $body);
    }

    /**
     * `external_id` and `author` are varchar(255); an over-long value from a
     * platform must not turn into a failed run.
     */
    private function clamp(string $value): string
    {
        return mb_substr($value, 0, 255);
    }

    private function logCapped(Integration $integration, int $pages, int $emptyStreak): void
    {
        $integrationId = (int) $integration->id;
        $platform = (string) $integration->platform;

        Log::warning('Connector run capped before the stream ended.', [
            'integration_id' => $integrationId,
            'platform' => $platform,
            'pages_fetched' => $pages,
            'empty_streak' => $emptyStreak,
        ]);
    }
}
