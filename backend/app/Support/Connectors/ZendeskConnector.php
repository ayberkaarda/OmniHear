<?php

namespace App\Support\Connectors;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Zendesk Support tickets, through the cursor-based incremental export.
 *
 * This is the first connector with credentials, so it is the first code path on
 * which invariant I5 is anything but trivially satisfied. Three structural
 * choices carry it, in order of how much they matter:
 *
 *  1. The credentials are constructor arguments held in private properties and
 *     handed straight to `withBasicAuth()`. They are never interpolated into a
 *     URL, never put in a query string, and never read back out of this object.
 *  2. Nothing this class throws is built from a response. Every failure is one
 *     of the fixed ConnectorFailure sentences, so `integrations.sync_error` and
 *     the job's warning line cannot carry credential material even if an
 *     upstream error body echoed the token back at us.
 *  3. This class logs nothing at all. The one warning in the ingestion path is
 *     IngestionRunner's, and it carries counts and ids.
 *
 * ## Why the cursor export rather than a search or a date filter
 *
 * Spec 6.1 forbids a full re-scan on every sync. Zendesk answers that directly:
 * `/api/v2/incremental/tickets/cursor.json` takes a `start_time` on the first
 * call and an opaque `cursor` afterwards, and tells us with `end_of_stream`
 * whether we have caught up. That flag is a better stop condition than a
 * timestamp watermark, because the export is ordered by when a ticket last
 * changed, not by when it was created — a two-year-old ticket updated this
 * morning arrives at the end of the stream with an old `created_at`. A
 * high-water mark on `created_at` would classify it as already seen and drop
 * it. So this connector does not use SyncCursor's watermark to decide anything;
 * it carries Zendesk's own cursor in SyncCursor::$token and stops when Zendesk
 * says the stream ended.
 *
 * ## Where the shape came from
 *
 * There is no Zendesk account behind this. The request shape, the response
 * envelope (`tickets`, `after_cursor`, `end_of_stream`), the API-token Basic
 * auth form (`{email}/token` : `{api_token}`), the ticket field set and the
 * error statuses are taken from Zendesk's published API documentation, and the
 * fixtures under tests/Fixtures/platforms/zendesk/ are synthesised from it.
 * contracts/fixtures/platforms/zendesk/README.md records, field by field, what
 * is documented and what is an inference — read it before trusting a detail
 * here against a live account.
 */
final readonly class ZendeskConnector implements PlatformConnector
{
    /**
     * Longest `after_cursor` this connector will store.
     *
     * `integrations.sync_cursor` is varchar(255) and the encoded SyncCursor has
     * to fit inside it. This connector's own cursor carries only the page number
     * and the token, but the ceiling is set for the worst case — a cursor
     * carrying a watermark and a pending value as well, which is roughly 100
     * characters of envelope. A documented Zendesk cursor is around 30
     * characters, so 120 is threefold headroom and anything above it means the
     * response is not what this connector thinks it is.
     */
    private const MAX_TOKEN_LENGTH = 120;

    public function __construct(
        private string $subdomain,
        private string $email,
        private string $apiToken,
        private string $baseUrlTemplate,
        private ConnectorLimits $limits,
        private int $timeout,
        private int $initialLookbackDays,
        private int $startTimeLagSeconds,
    ) {}

    public function fetchPage(?string $cursor): ConnectorPage
    {
        $state = SyncCursor::decode($cursor);

        $body = $this->decode($this->request($state->token));
        $tickets = $this->tickets($body);

        $pageWatermark = null;
        $items = [];

        foreach ($tickets as $ticket) {
            $publishedAt = $this->publishedAt($ticket);
            $pageWatermark = (new SyncCursor(watermark: $pageWatermark))->advancedTo($publishedAt)->watermark;

            $item = $this->toItem($ticket, $publishedAt);

            if ($item !== null) {
                $items[] = $item;
            }
        }

        // `end_of_stream` is the only thing that ends the run. An empty
        // `tickets` array does not: Zendesk answers one when every record in
        // the window was filtered out, and treating that as the end would bury
        // everything behind it. The runner's page cap is a separate,
        // runaway-loop ceiling and is the runner's to apply — reporting
        // hasMore=false when this connector hits it would tell the runner the
        // run was complete and let it promote a position it never reached.
        $hasMore = ! $this->endOfStream($body);
        $next = $this->afterCursor($body, required: $hasMore);

        // The page number is meaningless here — the token is the entire
        // position — so it is pinned at 1 and the watermark is left out of the
        // cursor entirely. Both keep the encoded cursor short, which is what
        // makes it certain to fit varchar(255) next to Zendesk's token.
        $nextCursor = (new SyncCursor)->withToken($next ?? $state->token);

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
        try {
            $this->decode($this->request(null));
        } catch (ConnectorException $e) {
            return ConnectorHealth::failing($e->failure());
        }

        return ConnectorHealth::ok();
    }

    /**
     * One call to the incremental export: `cursor` when we have one, otherwise
     * `start_time` for the initial backfill window.
     */
    private function request(?string $token): Response
    {
        $url = $this->baseUrl().'/api/v2/incremental/tickets/cursor.json';

        $query = $token !== null && $token !== ''
            ? ['cursor' => $token]
            : ['start_time' => $this->initialStartTime()];

        try {
            $response = $this->client()->get($url, $query);
        } catch (ConnectionException $e) {
            throw ConnectorException::of(ConnectorFailure::Unreachable, $e);
        }

        if ($response->successful()) {
            return $response;
        }

        throw ConnectorException::of(match (true) {
            // "Couldn't authenticate you" — the token or the email is wrong, or
            // API-token access is switched off for the account.
            $response->status() === 401 => ConnectorFailure::InvalidCredentials,
            // The credentials are valid but the agent lacks the export
            // permission. Still a credential problem from the user's side, and
            // still terminal: retrying changes nothing.
            $response->status() === 403 => ConnectorFailure::InvalidCredentials,
            // Wrong subdomain: the host resolved but the API is not there.
            $response->status() === 404 => ConnectorFailure::Misconfigured,
            // Documented for this endpoint: a start_time too close to now. The
            // lag below is what should keep us out of it, so reaching this means
            // the integration's configuration, not the platform, is at fault.
            $response->status() === 422 => ConnectorFailure::Misconfigured,
            $response->status() === 429 => ConnectorFailure::RateLimited,
            default => ConnectorFailure::Unreachable,
        });
    }

    /**
     * API-token authentication: the username carries the `/token` suffix and
     * the password is the token itself. withBasicAuth keeps both in the
     * Authorization header — they never reach the URL, the query string or a
     * log line.
     */
    private function client(): PendingRequest
    {
        return Http::withBasicAuth($this->email.'/token', $this->apiToken)
            ->acceptJson()
            ->timeout($this->timeout);
    }

    private function baseUrl(): string
    {
        return rtrim(str_replace('{subdomain}', $this->subdomain, $this->baseUrlTemplate), '/');
    }

    /**
     * Zendesk refuses a `start_time` within the last minute, so the initial
     * backfill window is clamped away from now as well as bounded behind it.
     */
    private function initialStartTime(): int
    {
        $now = CarbonImmutable::now();

        return min(
            $now->subDays(max(0, $this->initialLookbackDays))->getTimestamp(),
            $now->subSeconds(max(0, $this->startTimeLagSeconds))->getTimestamp(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Response $response): array
    {
        $decoded = $response->json();

        if (! is_array($decoded) || ! array_key_exists('tickets', $decoded)) {
            throw ConnectorException::of(ConnectorFailure::MalformedResponse);
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $body
     * @return list<array<string, mixed>>
     */
    private function tickets(array $body): array
    {
        $tickets = $body['tickets'];

        if (! is_array($tickets)) {
            throw ConnectorException::of(ConnectorFailure::MalformedResponse);
        }

        return array_values(array_filter($tickets, is_array(...)));
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function endOfStream(array $body): bool
    {
        $flag = $body['end_of_stream'] ?? null;

        if (! is_bool($flag)) {
            // Without this flag there is no stop condition at all, and guessing
            // either way is worse than refusing: guessing true loses the rest of
            // the stream, guessing false loops until the runner's cap.
            throw ConnectorException::of(ConnectorFailure::MalformedResponse);
        }

        return $flag;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function afterCursor(array $body, bool $required): ?string
    {
        $cursor = $body['after_cursor'] ?? null;

        if (! is_string($cursor) || $cursor === '') {
            if ($required) {
                // hasMore with nowhere to continue would restart the export from
                // start_time on every run — a full re-scan, which spec 6.1 forbids.
                throw ConnectorException::of(ConnectorFailure::MalformedResponse);
            }

            return null;
        }

        if (mb_strlen($cursor) > self::MAX_TOKEN_LENGTH) {
            // Storing it would overflow sync_cursor and fail the run with a
            // database error that says nothing useful. Fail here instead.
            throw ConnectorException::of(ConnectorFailure::MalformedResponse);
        }

        return $cursor;
    }

    /**
     * @param  array<string, mixed>  $ticket
     */
    private function toItem(array $ticket, ?string $publishedAt): ?ConnectorItem
    {
        $id = $ticket['id'] ?? null;

        if (! is_int($id) && ! (is_string($id) && $id !== '')) {
            return null;
        }

        // Incremental exports keep returning tickets after they are deleted, and
        // a deleted ticket carries no content. Ingesting one would put an empty
        // row in the inbox and spend a unit of analysis quota on it.
        if (($ticket['status'] ?? null) === 'deleted') {
            return null;
        }

        return new ConnectorItem(
            externalId: (string) $id,
            author: $this->requesterName($ticket),
            body: is_string($ticket['description'] ?? null) ? $ticket['description'] : '',
            sourceUrl: $this->baseUrl().'/agent/tickets/'.rawurlencode((string) $id),
            publishedAt: $publishedAt,
            rating: $this->rating($ticket),
            rawPayload: $ticket,
        );
    }

    /**
     * `created_at` rather than `updated_at`: published_at is when the customer
     * said this, and an agent's later reply must not move a comment forward in
     * the inbox or in the trend charts.
     *
     * @param  array<string, mixed>  $ticket
     */
    private function publishedAt(array $ticket): ?string
    {
        $value = $ticket['created_at'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * The display name the requester used, when the channel carries one. The
     * ticket itself only holds a numeric `requester_id`; resolving that would
     * mean sideloading the user record, which pulls a full profile — email
     * address included — into raw_payload for no analytical gain.
     *
     * @param  array<string, mixed>  $ticket
     */
    private function requesterName(array $ticket): ?string
    {
        $name = data_get($ticket, ['via', 'source', 'from', 'name']);

        return is_string($name) && trim($name) !== '' ? $name : null;
    }

    /**
     * Zendesk's CSAT is two-valued, so it is projected onto the ends of the
     * 1-5 scale the rest of the product already speaks. `offered` and
     * `unoffered` mean the customer never answered, which is not a rating.
     *
     * @param  array<string, mixed>  $ticket
     */
    private function rating(array $ticket): ?int
    {
        return match (data_get($ticket, ['satisfaction_rating', 'score'])) {
            'good', 'good_with_comment' => 5,
            'bad', 'bad_with_comment' => 1,
            default => null,
        };
    }
}
