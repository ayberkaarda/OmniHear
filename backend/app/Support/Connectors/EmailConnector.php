<?php

namespace App\Support\Connectors;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * A shared mailbox, over JMAP (RFC 8620 core, RFC 8621 mail).
 *
 * This is the fifth of spec 2's six channels and the only one whose "platform"
 * is a protocol rather than a vendor: any JMAP server answers it, which is why
 * the credentials are a session URL rather than a tenant name.
 *
 * ## Why JMAP rather than a vendor mail API
 *
 * Costed against Gmail REST before this class existed
 * (docs/contracts/w11-email-connector.md). Four measured points decided it, and
 * every one of them is visible in the code below:
 *
 *  1. `Email/query` chains straight into `Email/get` through a result
 *     reference (RFC 8620 section 3.7), so one page of messages is **one HTTP
 *     request**. Gmail's `messages.list` answers ids only, so every page costs
 *     one request per message — an N+1 no other connector in this tree has and
 *     one `ConnectorLimits` is not reasoned for.
 *  2. `Email/changes { sinceState }` is a real cursor. Gmail's `q=after:`
 *     takes a calendar date, so its watermark is day-granular and every run
 *     re-fetches a whole day through that N+1.
 *  3. `bodyValues` returns the decoded text inline. Gmail returns nested
 *     base64url `multipart/*` needing a MIME walk and charset handling.
 *  4. Authentication is a plain bearer token. A Gmail project in "Testing"
 *     status is issued a refresh token that expires after seven days, so the
 *     connector would break weekly for anyone who cloned this repository and
 *     pasted their own credentials.
 *
 * ## Where the shape came from
 *
 * Every request shape, response envelope and field name is taken from RFC 8620
 * and RFC 8621. The fixtures under tests/Fixtures/platforms/email/ were then
 * **recorded against a live JMAP account on 2026-09-05** — envelopes real,
 * messages written for this repository, the way the App Store fixtures were
 * rebuilt. contracts/fixtures/platforms/email/README.md separates what the
 * RFCs document, what that recording measured, what it **falsified**, and what
 * is still inferred. Read it before trusting a detail here against a server
 * that is not the one that was measured.
 *
 * Three results of that recording are load bearing in the code below and are
 * marked where they apply: the result reference really does answer one page in
 * one request; `Email/changes`'s `newState` is **not** the state its chained
 * `Email/get` reports; and the 404 branch in `request()` never fires on the
 * server that was measured, which answers a redirect for an unknown path.
 *
 * ## Invariant I5 — the API token
 *
 * The same three structural rules the other credentialed connectors carry:
 *
 *  1. The token is a constructor argument in a private property, handed to
 *     `withToken()` and nowhere else. It never reaches a URL, a query string or
 *     the request body.
 *  2. Nothing thrown here is built from a response. Every failure is one of the
 *     fixed ConnectorFailure sentences, so `integrations.sync_error` cannot
 *     carry credential material even if a server echoed the token back at us.
 *  3. This class logs nothing at all.
 *
 * There is a fourth rule this connector needs and the others do not: the
 * session response names the URL every later request goes to, so a session
 * document could otherwise redirect the bearer token at a host of its
 * choosing. `apiUrl` is therefore required to be an https URL before the token
 * is ever sent to it, exactly as `sessionUrl` is.
 *
 * ## The cursor model — token only
 *
 * Zendesk's shape, not the App Store's: the position is the server's own opaque
 * state string, carried in `SyncCursor::$token`, and no `published_at`
 * watermark is used at all. JMAP's `Email/changes` is ordered by change, not by
 * `receivedAt`, so a high-water mark on `receivedAt` would classify an old
 * message that was just filed into the mailbox as already seen and drop it.
 *
 *  - **No token yet (first run):** `Email/query` filtered to the mailbox,
 *    sorted newest-first, chained into `Email/get`. The `Email/get` response's
 *    `state` becomes the token.
 *  - **With a token:** `Email/changes { sinceState }` chained into `Email/get`
 *    on `/created`. `hasMoreChanges` becomes `hasMore` and `newState` becomes
 *    the next token.
 *  - **`cannotCalculateChanges`:** the server can no longer answer from that
 *    state (RFC 8620 section 5.2). A normal branch, not an exception: fall back
 *    to a full `Email/query` and take a fresh state. Invariant I2 turns the
 *    messages that come back a second time into zero new rows.
 *
 * The token is written to `integrations.sync_cursor` by IngestionRunner at the
 * end of the run, never by this class mid-run. A run that dies on page 3 leaves
 * the stored token where it was and the next run walks the same ground again;
 * that is the correct trade, because the alternative buries every unfetched
 * message behind a position the run never reached (docs/LESSONS.md).
 *
 * ## Decision: the first run is a bounded backfill
 *
 * `Email/query` is asked for one page and this connector reports
 * `hasMore = false` on it, so a cold integration ingests the newest
 * `$pageSize` messages of the mailbox and nothing older. It is bounded twice
 * over: by that single page, and by the query's own `after` filter, set to
 * `now - $initialLookbackDays` — the same bound ZendeskConnector applies with
 * the same setting name. Without it a mailbox holding four years of mail spends
 * four years of analysis quota on its first sync. The point of the first run is
 * to establish a state to be incremental from, not to import a decade of mail
 * into an inbox that charges quota per row. Everything that arrives after it is
 * picked up by `Email/changes`, which does page.
 *
 * ## Decision: only a text/plain part becomes the text
 *
 * RFC 8621 section 4.1.4 lets `textBody` carry the `text/html` part when a
 * message has no plain alternative, so "the text body" is not automatically
 * text. Reading it would mean writing an HTML stripper — quoting, entities,
 * `<style>` blocks — and the server already publishes `preview`, its own
 * plain-text extract of the message. So `bodyValues` is read for `text/plain`
 * parts only, and an HTML-only message falls back to `preview`.
 *
 * ## Decision: the subject joins the body
 *
 * `subject` and the message text are two fields that both carry meaning, and
 * the analyzer sees `feedbacks.body` and nothing else. So they are joined as
 * `subject . "\n\n" . text`, whichever exists is used alone when only one does,
 * and the message is **skipped** only when **both** are empty — a blank row
 * would sit in the inbox and spend a unit of analysis quota on nothing.
 *
 * This is the same decision TrustpilotConnector documents for `title` plus
 * `text`, taken for the same reason: on e-mail the subject is where the
 * sentiment usually lives. "Still no refund" carries the whole complaint even
 * when the body underneath it is one line.
 *
 * ## Decision: recipients are not fetched
 *
 * `properties` deliberately omits `to`, `cc` and `bcc`. They are this company's
 * own staff addresses, they carry no analytical signal, and `raw_payload`
 * stores whatever arrives — so not asking for them is the data minimisation
 * spec 8 requires, applied one step earlier than IngestionRunner's masking.
 */
final class EmailConnector implements PlatformConnector
{
    private const CORE_CAPABILITY = 'urn:ietf:params:jmap:core';

    private const MAIL_CAPABILITY = 'urn:ietf:params:jmap:mail';

    /**
     * Longest server state this connector will store.
     *
     * `integrations.sync_cursor` is varchar(255) and the encoded SyncCursor has
     * to fit inside it. This connector's cursor is `{"page":1,"token":"…"}`,
     * twenty-one characters of envelope, so 200 leaves the column comfortable.
     *
     * The live recording settled how much headroom that is: the measured server
     * answers `J` followed by decimal digits — **four characters** at the widest
     * observation. This ceiling is fifty times the real thing, and a state
     * longer than it means the response is not what this connector thinks it is.
     */
    private const MAX_TOKEN_LENGTH = 200;

    /**
     * Fallback for the session's `maxObjectsInGet` (RFC 8620 section 2). Used
     * only when a server omits it; the session's own value wins.
     */
    private const DEFAULT_MAX_OBJECTS_IN_GET = 500;

    /**
     * The Email properties this connector reads, plus the ones that make
     * `raw_payload` useful when a mapping has to be debugged.
     *
     * `to`, `cc` and `bcc` are absent on purpose — see the class docblock.
     *
     * @var list<string>
     */
    private const PROPERTIES = [
        'id',
        'blobId',
        'threadId',
        'mailboxIds',
        'size',
        'receivedAt',
        'sentAt',
        'messageId',
        'from',
        'subject',
        'hasAttachment',
        'preview',
        'textBody',
        'bodyValues',
    ];

    /**
     * The session document, reduced to what this connector uses. Memoised for
     * the lifetime of the object so a multi-page run pays for the session
     * fetch and the mailbox lookup once rather than once per page.
     *
     * @var array{apiUrl: string, accountId: string, maxObjectsInGet: int}|null
     */
    private ?array $session = null;

    private ?string $mailboxId = null;

    public function __construct(
        private readonly string $sessionUrl,
        private readonly string $apiToken,
        private readonly string $mailbox,
        private readonly ConnectorLimits $limits,
        private readonly int $timeout,
        private readonly int $pageSize,
        private readonly int $maxBodyBytes,
        private readonly int $initialLookbackDays,
    ) {
        // The session URL is a credential the user pastes, and every later
        // request — bearer token included — goes wherever it points. An `http`
        // or `file` scheme would put the token on the wire in clear or on the
        // local filesystem, so the scheme is whitelisted rather than trusted.
        if (! OutboundHostPolicy::isHttpsUrl($sessionUrl)) {
            throw ConnectorException::of(ConnectorFailure::Misconfigured);
        }

        if (trim($mailbox) === '') {
            throw ConnectorException::of(ConnectorFailure::Misconfigured);
        }
    }

    public function fetchPage(?string $cursor): ConnectorPage
    {
        $token = SyncCursor::decode($cursor)->token;

        return $token === null || $token === ''
            ? $this->initialPage()
            : $this->incrementalPage($token);
    }

    public function limits(): ConnectorLimits
    {
        return $this->limits;
    }

    /**
     * Reachability plus "does the named mailbox exist".
     *
     * The second half is the point. A mistyped mailbox name is the most likely
     * configuration error on this channel, and without this check it would
     * present as a healthy integration that never finds any feedback — the one
     * failure mode a user cannot diagnose from the UI.
     */
    public function healthCheck(): ConnectorHealth
    {
        try {
            $this->session();
            $this->mailboxId();
        } catch (ConnectorException $e) {
            return ConnectorHealth::failing($e->failure());
        }

        return ConnectorHealth::ok();
    }

    /*
    |----------------------------------------------------------------------
    | The two page shapes
    |----------------------------------------------------------------------
    */

    /**
     * No stored state: query the mailbox for its newest page and keep the
     * `Email/get` state as the position everything after this is relative to.
     */
    private function initialPage(): ConnectorPage
    {
        $accountId = $this->session()['accountId'];
        $mailboxId = $this->mailboxId();

        $body = $this->call([
            [
                'Email/query',
                [
                    'accountId' => $accountId,
                    'filter' => [
                        'inMailbox' => $mailboxId,
                        // The cold-start bound. `after` is a FilterCondition on
                        // `receivedAt` (RFC 8621 section 4.4.1) and it is what
                        // stops a first sync from reading — and paying analysis
                        // quota for — every message a four-year-old mailbox
                        // holds. Anything older than the window is never read,
                        // by design, and the fixtures README says so.
                        'after' => $this->initialAfter(),
                    ],
                    'sort' => [['property' => 'receivedAt', 'isAscending' => false]],
                    'position' => 0,
                    'limit' => $this->pageSize(),
                    'calculateTotal' => false,
                ],
                'q0',
            ],
            $this->emailGetCall('q0', 'Email/query', '/ids'),
        ]);

        // Read even though nothing below uses it: a `Email/query` that failed
        // has to surface as its own failure rather than as an empty
        // `Email/get`, which would look like a mailbox with no mail in it.
        $this->methodResponse($body, 'Email/query', 'q0');
        $get = $this->methodResponse($body, 'Email/get', 'g0');

        $emails = $this->emails($get);

        return new ConnectorPage(
            items: $this->items($emails, $mailboxId),
            // One page and no more. The backfill is bounded on purpose — see
            // the class docblock — and there is nowhere to carry a query
            // position anyway: the cursor holds a server state, not an offset.
            hasMore: false,
            nextCursor: $this->cursorFor($this->state($get)),
            watermark: $this->watermark($emails),
        );
    }

    /**
     * With a stored state: ask what changed since it.
     */
    private function incrementalPage(string $token): ConnectorPage
    {
        $accountId = $this->session()['accountId'];
        $mailboxId = $this->mailboxId();

        $body = $this->call([
            [
                'Email/changes',
                [
                    'accountId' => $accountId,
                    'sinceState' => $token,
                    'maxChanges' => $this->pageSize(),
                ],
                'c0',
            ],
            $this->emailGetCall('c0', 'Email/changes', '/created'),
        ]);

        // RFC 8620 section 5.2: the server may no longer be able to answer from
        // that state — it expired, or too much changed. Documented, expected,
        // and recoverable, so it is a branch rather than a failure. The full
        // query re-reads messages this integration already has and invariant I2
        // turns them into zero new rows.
        if ($this->hasMethodError($body, 'c0', 'cannotCalculateChanges')) {
            return $this->initialPage();
        }

        $changes = $this->methodResponse($body, 'Email/changes', 'c0');
        $get = $this->methodResponse($body, 'Email/get', 'g0');

        $hasMore = $changes['hasMoreChanges'] ?? null;

        if (! is_bool($hasMore)) {
            // Without this flag there is no stop condition. Guessing true loses
            // the rest of the changes; guessing false spins until the runner's
            // page cap. Refusing is the only honest answer.
            throw ConnectorException::of(ConnectorFailure::MalformedResponse);
        }

        // `newState` and nothing else. The chained Email/get reports the
        // account's CURRENT state, which on a window capped by maxChanges runs
        // ahead of where this page actually reached — measured during the live
        // recording, where a capped window answered a newState thirty change
        // numbers behind its own Email/get. Reading the token off Email/get
        // would silently skip every change between the two.
        $newState = $this->token($changes['newState'] ?? null);

        // A server that reports more changes while handing back the state we
        // just sent would loop until the runner's cap on every single run.
        if ($hasMore && $newState === $token) {
            throw ConnectorException::of(ConnectorFailure::MalformedResponse);
        }

        $emails = $this->emails($get);

        return new ConnectorPage(
            items: $this->items($emails, $mailboxId),
            // `hasMoreChanges` and nothing else. An empty `created` list does
            // not end the run — every change on this page may have been an
            // update or a destroy — and neither does the runner's page cap:
            // reporting hasMore=false there would tell IngestionRunner the run
            // completed and let it store a position it never reached.
            hasMore: $hasMore,
            nextCursor: $this->cursorFor($newState),
            watermark: $this->watermark($emails),
        );
    }

    /**
     * The `Email/get` half of both requests, referencing the ids the call
     * before it produced.
     *
     * This is the result reference of RFC 8620 section 3.7 and it is the whole
     * reason this connector is one request per page instead of one per message.
     * The live recording confirmed a real server evaluates it: both method
     * responses came back in a single HTTP response with the ids resolved, on
     * the Email/query path and on the Email/changes path alike.
     *
     * @return array{0: string, 1: array<string, mixed>, 2: string}
     */
    private function emailGetCall(string $resultOf, string $name, string $path): array
    {
        return [
            'Email/get',
            [
                'accountId' => $this->session()['accountId'],
                '#ids' => ['resultOf' => $resultOf, 'name' => $name, 'path' => $path],
                'properties' => self::PROPERTIES,
                // Only the text parts, and bounded: `raw_payload` keeps whatever
                // arrives, and an unbounded body value would put a 10 MB message
                // into a database column.
                'fetchTextBodyValues' => true,
                'maxBodyValueBytes' => max(1, $this->maxBodyBytes),
            ],
            'g0',
        ];
    }

    /*
    |----------------------------------------------------------------------
    | Session and mailbox — resolved once per connector instance
    |----------------------------------------------------------------------
    */

    /**
     * @return array{apiUrl: string, accountId: string, maxObjectsInGet: int}
     */
    private function session(): array
    {
        if ($this->session !== null) {
            return $this->session;
        }

        // The session URL is tenant-supplied and this is the first request that
        // dereferences it. Check the host before the GET and — through the
        // client's allow_redirects — at every redirect hop the autodiscovery
        // path may take, so the bearer token is never carried to an internal
        // address (invariant I5).
        OutboundHostPolicy::assertAllowed($this->sessionUrl);

        $decoded = $this->request('GET', $this->sessionUrl)->json();

        // Is this a JMAP Session resource at all?
        //
        // A mis-pasted session URL is the most likely configuration error on
        // this channel, and the live recording (2026-09-05) showed the branch
        // that was meant to catch it — 404 — never fires: the measured server
        // answers an unknown path with a 302 to a documentation page, which the
        // client follows, so what arrives is 200 and a page of HTML. That used
        // to surface as MalformedResponse, telling the user their mail provider
        // had returned something unparseable when in fact they had typed the
        // URL wrong.
        //
        // The status code cannot be the discriminator, because the same
        // recording showed <session-url>/<stray suffix> answering 200 with a
        // complete and perfectly usable session document. The shape can: RFC
        // 8620 section 2 requires these members, so a body without them is not
        // a session resource, whatever answered it.
        $isSessionResource = is_array($decoded)
            && array_key_exists('capabilities', $decoded)
            && array_key_exists('accounts', $decoded)
            && array_key_exists('apiUrl', $decoded);

        if (! $isSessionResource) {
            throw ConnectorException::of(ConnectorFailure::Misconfigured);
        }

        $apiUrl = $decoded['apiUrl'] ?? null;

        // The bearer token is about to be sent here. A session document naming
        // a plain-http endpoint would put it on the wire in clear, so this is
        // refused rather than followed (invariant I5).
        //
        // MalformedResponse and not Misconfigured, deliberately: the check
        // above has already established this *is* a JMAP server, so the fault
        // is in what that server said, not in what the user typed. Both are
        // terminal, so neither is retried; the difference is which sentence
        // reaches integrations.sync_error.
        if (! is_string($apiUrl) || ! OutboundHostPolicy::isHttpsUrl($apiUrl)) {
            throw ConnectorException::of(ConnectorFailure::MalformedResponse);
        }

        // The apiUrl is chosen by the server the session URL resolved to, not by
        // the tenant, so a compromised or hostile JMAP server could point the
        // token — about to be POSTed here — at an internal address the session
        // URL itself would never have passed. The scheme check above stays
        // MalformedResponse (the fault is in what a JMAP server said); the host
        // check is the policy's, and a private target is Misconfigured.
        OutboundHostPolicy::assertAllowed($apiUrl);

        $capabilities = $decoded['capabilities'] ?? null;

        if (! is_array($capabilities) || ! array_key_exists(self::MAIL_CAPABILITY, $capabilities)) {
            // A JMAP server that does not speak the mail capability is a
            // configuration mistake, not a fault: this is a valid server behind
            // the wrong URL.
            throw ConnectorException::of(ConnectorFailure::Misconfigured);
        }

        $accountId = data_get($decoded, ['primaryAccounts', self::MAIL_CAPABILITY]);

        if (! is_string($accountId) || $accountId === '') {
            throw ConnectorException::of(ConnectorFailure::Misconfigured);
        }

        $maxObjectsInGet = data_get($decoded, ['capabilities', self::CORE_CAPABILITY, 'maxObjectsInGet']);

        return $this->session = [
            'apiUrl' => $apiUrl,
            'accountId' => $accountId,
            'maxObjectsInGet' => is_int($maxObjectsInGet) && $maxObjectsInGet > 0
                ? $maxObjectsInGet
                : self::DEFAULT_MAX_OBJECTS_IN_GET,
        ];
    }

    /**
     * The id of the mailbox the integration names.
     *
     * The credential carries a **name**, not an id, because ids are
     * per-account opaque strings and a value a user pastes into a form must not
     * encode one. `Mailbox/query`'s `name` condition is a substring match
     * (RFC 8621 section 2.3), which would silently pick "Support Archive" for
     * "Support", so the whole list is fetched and matched here instead.
     */
    private function mailboxId(): string
    {
        if ($this->mailboxId !== null) {
            return $this->mailboxId;
        }

        $body = $this->call([
            [
                'Mailbox/get',
                [
                    'accountId' => $this->session()['accountId'],
                    'ids' => null,
                    'properties' => ['id', 'name', 'role', 'parentId'],
                ],
                'm0',
            ],
        ]);

        $list = $this->methodResponse($body, 'Mailbox/get', 'm0')['list'] ?? null;

        if (! is_array($list)) {
            throw ConnectorException::of(ConnectorFailure::MalformedResponse);
        }

        $wanted = trim($this->mailbox);
        $insensitive = null;

        foreach ($list as $mailbox) {
            if (! is_array($mailbox)) {
                continue;
            }

            $name = $mailbox['name'] ?? null;
            $id = $mailbox['id'] ?? null;

            if (! is_string($name) || ! is_string($id) || $id === '') {
                continue;
            }

            if ($name === $wanted) {
                return $this->mailboxId = $id;
            }

            // Kept as a second choice rather than taken immediately: JMAP
            // mailbox names are case-sensitive, so an exact match anywhere in
            // the list must win over a case-insensitive one earlier in it.
            if ($insensitive === null && mb_strtolower($name) === mb_strtolower($wanted)) {
                $insensitive = $id;
            }
        }

        if ($insensitive !== null) {
            return $this->mailboxId = $insensitive;
        }

        // Misconfigured, not "no feedback yet". A mistyped mailbox name is the
        // most likely configuration error on this channel and it has to say so.
        throw ConnectorException::of(ConnectorFailure::Misconfigured);
    }

    /*
    |----------------------------------------------------------------------
    | HTTP
    |----------------------------------------------------------------------
    */

    /**
     * One JMAP API request.
     *
     * @param  list<array{0: string, 1: array<string, mixed>, 2: string}>  $methodCalls
     * @return array<string, mixed>
     */
    private function call(array $methodCalls): array
    {
        $response = $this->request('POST', $this->session()['apiUrl'], [
            'using' => [self::CORE_CAPABILITY, self::MAIL_CAPABILITY],
            'methodCalls' => $methodCalls,
        ]);

        $decoded = $response->json();

        if (! is_array($decoded) || ! is_array($decoded['methodResponses'] ?? null)) {
            throw ConnectorException::of(ConnectorFailure::MalformedResponse);
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function request(string $method, string $url, ?array $payload = null): Response
    {
        try {
            $response = $payload === null
                ? $this->client()->get($url)
                : $this->client()->post($url, $payload);
        } catch (ConnectionException $e) {
            throw ConnectorException::of(ConnectorFailure::Unreachable, $e);
        }

        if ($response->successful()) {
            return $response;
        }

        throw ConnectorException::of(match (true) {
            // 401 for an absent or rejected bearer token, 403 for a token the
            // server knows but will not let read this account. Both terminal.
            // The live recording settled the 401 half: the measured server
            // answers 401 for a malformed token and for a well-formed unknown
            // one alike, with a one-line text/plain body. The 403 half stayed
            // inferred — a trial account has no second account to be refused —
            // and RFC 8620 pins no status code per condition (section 2,
            // section 8), so it remains a reading of the specification.
            in_array($response->status(), [401, 403], true) => ConnectorFailure::InvalidCredentials,
            // No JMAP session resource at that URL — the pasted session URL is
            // wrong, which is a setting rather than a credential.
            //
            // The recording falsified this for the server it measured: an
            // unknown path answers a 302 to a documentation page, and a garbage
            // suffix on the session URL answers 200 with the whole session
            // document. Kept for JMAP servers that do answer 404; see the
            // fixtures README, "What the recording falsified".
            $response->status() === 404 => ConnectorFailure::Misconfigured,
            $response->status() === 429 => ConnectorFailure::RateLimited,
            // A 3xx that reached this far means the client could not resolve
            // the redirect: the chain was exhausted, or there was no usable
            // Location. Redirects themselves are normal and are followed —
            // RFC 8620 section 2 makes /.well-known/jmap an autodiscovery path
            // servers are expected to redirect from, so refusing 3xx outright
            // would break conforming servers. One that cannot be followed is a
            // wrong URL, though, and mapping it to Unreachable spent five queue
            // attempts blaming the platform for a value the user typed.
            $response->status() >= 300 && $response->status() < 400 => ConnectorFailure::Misconfigured,
            // RFC 8620 section 3.6.1: a request-level problem — `notRequest`,
            // `notJSON`, `unknownCapability`, `limit`. Terminal, not transient:
            // the request shape or the declared capabilities are what the server
            // refuses, and that repeats identically however often it is retried.
            // Mapping it to Unreachable would spend five queue attempts blaming
            // the platform for a problem in the integration.
            $response->status() === 400 => ConnectorFailure::Misconfigured,
            default => ConnectorFailure::Unreachable,
        });
    }

    /**
     * The token rides in the Authorization header and nowhere else — never in
     * the URL, never in the query string, never in the JSON body.
     */
    private function client(): PendingRequest
    {
        return Http::withToken($this->apiToken)
            ->acceptJson()
            ->timeout($this->timeout)
            // Redirects stay on for the /.well-known/jmap autodiscovery path
            // (RFC 8620 section 2), but every hop's target is re-validated by the
            // policy before it is followed and only https is followed at all, so
            // the token cannot be redirected to an internal address.
            ->withOptions(['allow_redirects' => OutboundHostPolicy::redirectOptions()]);
    }

    /*
    |----------------------------------------------------------------------
    | Reading the JMAP response envelope
    |----------------------------------------------------------------------
    */

    /**
     * The arguments of one method response, by call id.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function methodResponse(array $body, string $name, string $callId): array
    {
        $found = $this->methodResponseTriple($body, $callId);

        if ($found === null) {
            throw ConnectorException::of(ConnectorFailure::MalformedResponse);
        }

        if ($found[0] === 'error') {
            throw ConnectorException::of($this->failureForMethodError($found[1]));
        }

        if ($found[0] !== $name) {
            throw ConnectorException::of(ConnectorFailure::MalformedResponse);
        }

        return $found[1];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function hasMethodError(array $body, string $callId, string $type): bool
    {
        $found = $this->methodResponseTriple($body, $callId);

        return $found !== null && $found[0] === 'error' && ($found[1]['type'] ?? null) === $type;
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array{0: string, 1: array<string, mixed>}|null
     */
    private function methodResponseTriple(array $body, string $callId): ?array
    {
        /** @var list<mixed> $responses */
        $responses = $body['methodResponses'];

        foreach ($responses as $response) {
            if (! is_array($response) || count($response) < 3) {
                continue;
            }

            if (($response[2] ?? null) !== $callId) {
                continue;
            }

            $name = $response[0] ?? null;
            $arguments = $response[1] ?? null;

            if (! is_string($name) || ! is_array($arguments)) {
                return null;
            }

            return [$name, $arguments];
        }

        return null;
    }

    /**
     * RFC 8620 section 3.6.2. Only `serverFail` and its siblings describe a
     * server that might answer differently in a minute; everything else — an
     * account we cannot see, a method or filter the server does not implement —
     * repeats identically, so it is terminal and the user has to be told to
     * look at the integration rather than to wait.
     *
     * @param  array<string, mixed>  $error
     */
    private function failureForMethodError(array $error): ConnectorFailure
    {
        return match ($error['type'] ?? null) {
            'serverFail', 'serverPartialFail', 'serverUnavailable' => ConnectorFailure::Unreachable,
            'forbidden' => ConnectorFailure::InvalidCredentials,
            default => ConnectorFailure::Misconfigured,
        };
    }

    /**
     * The `list` of an `Email/get` response.
     *
     * @param  array<string, mixed>  $get
     * @return list<array<string, mixed>>
     */
    private function emails(array $get): array
    {
        $list = $get['list'] ?? null;

        if (! is_array($list)) {
            throw ConnectorException::of(ConnectorFailure::MalformedResponse);
        }

        return array_values(array_filter($list, is_array(...)));
    }

    /**
     * The object state of an `Email/get` response — the position an
     * `Email/changes` is taken relative to.
     *
     * @param  array<string, mixed>  $get
     */
    private function state(array $get): string
    {
        return $this->token($get['state'] ?? null);
    }

    private function token(mixed $value): string
    {
        if (! is_string($value) || $value === '') {
            // Without a state there is no incremental position, and continuing
            // without one means a full query on every run — a re-scan, which
            // spec 6.1 forbids.
            throw ConnectorException::of(ConnectorFailure::MalformedResponse);
        }

        if (mb_strlen($value) > self::MAX_TOKEN_LENGTH) {
            // Storing it would overflow sync_cursor and fail the run with a
            // database error that says nothing useful. Fail here instead.
            throw ConnectorException::of(ConnectorFailure::MalformedResponse);
        }

        return $value;
    }

    /**
     * The cursor for the next page or the next run.
     *
     * Only the token travels. The page number is meaningless here — the state
     * is the entire position — and no watermark is used at all, so leaving both
     * out is what keeps the encoded cursor comfortably inside varchar(255).
     */
    private function cursorFor(string $token): string
    {
        return (new SyncCursor)->withToken($token)->encode();
    }

    /*
    |----------------------------------------------------------------------
    | Mapping
    |----------------------------------------------------------------------
    */

    /**
     * @param  list<array<string, mixed>>  $emails
     * @return list<ConnectorItem>
     */
    private function items(array $emails, string $mailboxId): array
    {
        $items = [];

        foreach ($emails as $email) {
            $item = $this->toItem($email, $mailboxId);

            if ($item !== null) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $email
     */
    private function toItem(array $email, string $mailboxId): ?ConnectorItem
    {
        $externalId = $this->trimmed($email['id'] ?? null);

        if ($externalId === null) {
            return null;
        }

        // `Email/changes` reports every change in the **account**, not only in
        // the watched mailbox, so membership is checked here. Without it a
        // company watching "Support" would ingest its own Sent folder.
        if (! $this->inMailbox($email, $mailboxId)) {
            return null;
        }

        $body = $this->body($email);

        if ($body === null) {
            return null;
        }

        return new ConnectorItem(
            externalId: $externalId,
            author: $this->author($email),
            body: $body,
            // JMAP has no canonical web URL for a message: `downloadUrl` serves
            // the raw blob and is not a page a person can open. Null rather
            // than an invented link.
            sourceUrl: null,
            publishedAt: $this->trimmed($email['receivedAt'] ?? null),
            // E-mail carries no rating. ConnectorItem::$rating is nullable for
            // exactly this case.
            rating: null,
            rawPayload: $email,
        );
    }

    /**
     * @param  array<string, mixed>  $email
     */
    private function inMailbox(array $email, string $mailboxId): bool
    {
        $mailboxIds = $email['mailboxIds'] ?? null;

        // `mailboxIds` is an Id[Boolean] map (RFC 8621 section 4.1.1): the key
        // is the mailbox and the value is always true. An absent map means the
        // server told us nothing about where this message lives, and ingesting
        // it would be a guess.
        return is_array($mailboxIds) && ($mailboxIds[$mailboxId] ?? null) === true;
    }

    /**
     * The subject and the message text, joined.
     *
     * Both are read, because the analyzer sees `feedbacks.body` and nothing
     * else and an e-mail's sentiment usually lives in its subject line — the
     * same call TrustpilotConnector makes for `title` plus `text`, recorded in
     * both class docblocks. Whichever exists is used alone when only one does,
     * and null — meaning **skip the message** — comes back only when both are
     * empty, because a blank row spends a unit of analysis quota on nothing.
     *
     * @param  array<string, mixed>  $email
     */
    private function body(array $email): ?string
    {
        $subject = $this->trimmed($email['subject'] ?? null);
        $text = $this->text($email);

        if ($subject !== null && $text !== null) {
            return $subject."\n\n".$text;
        }

        return $subject ?? $text;
    }

    /**
     * The plain-text body, or the server's own preview when there is no
     * text/plain part. Null when the message has no words in it at all.
     *
     * @param  array<string, mixed>  $email
     */
    private function text(array $email): ?string
    {
        $values = $email['bodyValues'] ?? null;
        $parts = $email['textBody'] ?? null;

        if (is_array($values) && is_array($parts)) {
            $chunks = [];

            foreach ($parts as $part) {
                if (! is_array($part) || ($part['type'] ?? null) !== 'text/plain') {
                    // A text/html part here means the message has no plain
                    // alternative (RFC 8621 section 4.1.4). `preview` below is
                    // the server's own extract of it and is a better answer
                    // than a hand-rolled HTML stripper.
                    continue;
                }

                $partId = $part['partId'] ?? null;

                if (! is_string($partId)) {
                    continue;
                }

                $value = $this->trimmed(data_get($values, [$partId, 'value']));

                if ($value !== null) {
                    $chunks[] = $value;
                }
            }

            if ($chunks !== []) {
                return implode("\n\n", $chunks);
            }
        }

        return $this->trimmed($email['preview'] ?? null);
    }

    /**
     * The sender's display name, or the local part of the address.
     *
     * Never the whole address: IngestionRunner masks anything matching an
     * address to `[email]` before it is stored (spec 8), so passing
     * `sender@example.invalid` through would put the literal string `[email]`
     * in the author column of every row and throw the sender's identity away.
     * The local part carries no domain and survives that pass.
     *
     * @param  array<string, mixed>  $email
     */
    private function author(array $email): ?string
    {
        $from = $email['from'] ?? null;

        if (! is_array($from) || $from === []) {
            return null;
        }

        $first = reset($from);

        if (! is_array($first)) {
            return null;
        }

        $name = $this->trimmed($first['name'] ?? null);

        if ($name !== null) {
            return $name;
        }

        $address = $this->trimmed($first['email'] ?? null);

        if ($address === null) {
            return null;
        }

        $at = mb_strpos($address, '@');

        return $at === false ? $address : $this->trimmed(mb_substr($address, 0, $at));
    }

    /**
     * The newest `receivedAt` on the page.
     *
     * Informational only: this connector's position is the server state, and
     * nothing here compares against a watermark. It is published because
     * ConnectorPage carries it and a caller reading a page in isolation should
     * be able to see how fresh it is.
     *
     * @param  list<array<string, mixed>>  $emails
     */
    private function watermark(array $emails): ?string
    {
        $watermark = null;

        foreach ($emails as $email) {
            $watermark = (new SyncCursor(watermark: $watermark))
                ->advancedTo($this->trimmed($email['receivedAt'] ?? null))
                ->watermark;
        }

        return $watermark;
    }

    /**
     * How many messages one page asks for, never more than the server said it
     * will return from a single `Email/get` (RFC 8620 section 2,
     * `maxObjectsInGet`) — the `Email/query` and the `Email/get` are one
     * request, so exceeding it would truncate the page silently.
     */
    private function pageSize(): int
    {
        return max(1, min($this->pageSize, $this->session()['maxObjectsInGet']));
    }

    /**
     * How far back the very first run reaches.
     *
     * JMAP's `UTCDate` is an ISO-8601 instant in UTC with a literal `Z` (RFC
     * 8620 section 1.4), which is what `toIso8601ZuluString()` produces — not
     * `toDateTimeString()`, which drops the zone entirely and has already put a
     * row seven hours off the real instant once (docs/LESSONS.md).
     */
    private function initialAfter(): string
    {
        return CarbonImmutable::now()
            ->subDays(max(0, $this->initialLookbackDays))
            ->toIso8601ZuluString();
    }

    /**
     * A non-empty string, or null.
     */
    private function trimmed(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
