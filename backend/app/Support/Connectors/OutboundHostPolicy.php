<?php

namespace App\Support\Connectors;

use Psr\Http\Message\UriInterface;

/**
 * The single gate every tenant-supplied outbound URL passes through before the
 * connectors fetch it.
 *
 * Two connectors let a tenant name the host the server will fetch on the
 * five-minute scheduler: EmailConnector's `session_url` (and the `apiUrl` a JMAP
 * session document then advertises) and MastodonConnector's `instance_url`.
 * Without a host check, `https://attacker/r` answering `302
 * http://169.254.169.254/...` or `https://127.0.0.1/` turns the scheduler into a
 * confused deputy against the cloud metadata endpoint or a service on the
 * loopback interface, and `integrations.sync_error` reflects the outcome back to
 * the tenant as an oracle.
 *
 * Redirect-following is deliberate and stays on: RFC 8620 section 2 makes
 * `/.well-known/jmap` an autodiscovery path servers redirect from, a behaviour
 * EmailConnectorTest pins. So rather than disabling redirects, the host is
 * validated at the first request **and again at every redirect hop** — Guzzle
 * calls `on_redirect` before it follows, and an exception from it cancels the
 * hop (RedirectMiddleware.php around line 100).
 *
 * ## Failure mapping (invariant I5 — nothing variable reaches the message)
 *
 * Every rejection throws the connectors' own ConnectorException with a fixed
 * ConnectorFailure case; no response body, header or URL is ever put into the
 * string. A scheme, name or range rejection is `Misconfigured` — the tenant
 * pointed the integration somewhere it may not go. A host that does not resolve
 * is `Unreachable`, not "allowed by omission".
 *
 * ## Residual: DNS rebinding is out of scope, on purpose
 *
 * This validates the addresses a host resolves to at check time; it does not pin
 * the connection to them. A host that answers a public address here and a
 * private one microseconds later, when the HTTP client resolves it again, is not
 * closed off. Doing so needs `CURLOPT_RESOLVE` pinning with a hand-rolled
 * redirect loop, which `Http::fake` cannot exercise and which needs a live
 * target to prove. With no production deployment to rebind against (D-09) the
 * residual is recorded rather than built; see the ADR.
 */
final class OutboundHostPolicy
{
    /**
     * IPv4 ranges no outbound fetch may reach: this host, the link-local and
     * cloud-metadata block, RFC 1918 private space, the carrier-grade NAT and
     * benchmarking ranges, IETF protocol assignments, and multicast/reserved.
     *
     * @var list<string>
     */
    private const IPV4_BLOCKED = [
        '0.0.0.0/8',
        '10.0.0.0/8',
        '100.64.0.0/10',
        '127.0.0.0/8',
        '169.254.0.0/16',
        '172.16.0.0/12',
        '192.0.0.0/24',
        '192.168.0.0/16',
        '198.18.0.0/15',
        '224.0.0.0/4',
        '240.0.0.0/4',
    ];

    /**
     * IPv6 ranges. `::ffff:0:0/96` is not listed here: a mapped address is
     * unwrapped to its IPv4 form and the IPv4 rule applies instead, so a mapping
     * of a public v4 stays reachable and a mapping of a private one is caught by
     * the v4 table above.
     *
     * @var list<string>
     */
    private const IPV6_BLOCKED = [
        '::/128',
        '::1/128',
        'fc00::/7',
        'fe80::/10',
        '64:ff9b::/96',
    ];

    private static ?DnsResolver $resolver = null;

    /**
     * Swap the resolver. Tests bind a fake; production never calls this and the
     * lazy default below is the system resolver.
     */
    public static function resolveUsing(?DnsResolver $resolver): void
    {
        self::$resolver = $resolver;
    }

    /**
     * The Guzzle `allow_redirects` block the connectors install on their client.
     *
     * `on_redirect` re-runs the full check on the hop's target *before* Guzzle
     * follows it, so an internal address reached through a redirect is refused
     * exactly as a first-hop one is. `protocols` is a second lock on the same
     * concern: a redirect to `http` is refused by Guzzle before `on_redirect`
     * would even see it.
     *
     * @return array{max: int, protocols: list<string>, on_redirect: callable}
     */
    public static function redirectOptions(): array
    {
        return [
            'max' => 3,
            'protocols' => ['https'],
            'on_redirect' => fn (...$a) => self::assertAllowed($a[2]),
        ];
    }

    /**
     * Throw unless the URL is an https URL whose host resolves entirely to
     * addresses outside the blocked ranges.
     *
     * @throws ConnectorException
     */
    public static function assertAllowed(string|UriInterface $url): void
    {
        $url = (string) $url;

        if (! self::isHttpsUrl($url)) {
            throw ConnectorException::of(ConnectorFailure::Misconfigured);
        }

        $host = self::normalizeHost((string) parse_url($url, PHP_URL_HOST));

        if ($host === '' || self::isBlockedName($host)) {
            throw ConnectorException::of(ConnectorFailure::Misconfigured);
        }

        // A host given as an IP literal is its own target; there is nothing to
        // resolve and the range test applies to the literal directly.
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            if (self::isBlockedAddress($host)) {
                throw ConnectorException::of(ConnectorFailure::Misconfigured);
            }

            return;
        }

        $addresses = self::resolver()->resolve($host);

        if ($addresses === []) {
            throw ConnectorException::of(ConnectorFailure::Unreachable);
        }

        foreach ($addresses as $address) {
            if (self::isBlockedAddress($address)) {
                throw ConnectorException::of(ConnectorFailure::Misconfigured);
            }
        }
    }

    /**
     * Scheme-and-shape check with no network call.
     *
     * The connectors' constructors and the settings form both need to refuse a
     * plainly wrong value (`http`, a `file` URL, a non-URL) at the point it is
     * entered, before any fetch. This is the single home for that rule — the
     * `isHttpsUrl` helper that used to be copied into each connector.
     */
    public static function isHttpsUrl(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        return strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https'
            && is_string(parse_url($url, PHP_URL_HOST))
            && parse_url($url, PHP_URL_HOST) !== '';
    }

    private static function resolver(): DnsResolver
    {
        return self::$resolver ??= new SystemDnsResolver;
    }

    private static function normalizeHost(string $host): string
    {
        // parse_url keeps the brackets on an IPv6 literal; strip them so the
        // address validators see `::1`, not `[::1]`. Trailing dot and case are
        // not significant in a host name.
        $host = trim($host);

        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }

        return rtrim(strtolower($host), '.');
    }

    private static function isBlockedName(string $host): bool
    {
        if ($host === 'localhost' || $host === 'metadata.google.internal') {
            return true;
        }

        foreach (['.localhost', '.internal', '.local'] as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return true;
            }
        }

        return false;
    }

    private static function isBlockedAddress(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return self::ipv4Blocked($ip);
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $mapped = self::mappedIpv4($ip);

            return $mapped !== null
                ? self::ipv4Blocked($mapped)
                : self::ipv6Blocked($ip);
        }

        // Not an address this policy can reason about — fail closed.
        return true;
    }

    private static function ipv4Blocked(string $ip): bool
    {
        foreach (self::IPV4_BLOCKED as $cidr) {
            if (self::ipv4InCidr($ip, $cidr)) {
                return true;
            }
        }

        return false;
    }

    private static function ipv6Blocked(string $ip): bool
    {
        foreach (self::IPV6_BLOCKED as $cidr) {
            if (self::ipv6InCidr($ip, $cidr)) {
                return true;
            }
        }

        return false;
    }

    private static function ipv4InCidr(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr);
        $bits = (int) $bits;

        $ipLong = ip2long($ip) & 0xFFFFFFFF;
        $subnetLong = ip2long($subnet) & 0xFFFFFFFF;
        $mask = $bits === 0 ? 0 : ((0xFFFFFFFF << (32 - $bits)) & 0xFFFFFFFF);

        return ($ipLong & $mask) === ($subnetLong & $mask);
    }

    private static function ipv6InCidr(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr);
        $bits = (int) $bits;

        $ipBin = inet_pton($ip);
        $subnetBin = inet_pton($subnet);

        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== 16) {
            return false;
        }

        $wholeBytes = intdiv($bits, 8);
        $remainder = $bits % 8;

        if ($wholeBytes > 0 && strncmp($ipBin, $subnetBin, $wholeBytes) !== 0) {
            return false;
        }

        if ($remainder === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainder)) & 0xFF;

        return (ord($ipBin[$wholeBytes]) & $mask) === (ord($subnetBin[$wholeBytes]) & $mask);
    }

    /**
     * The dotted-quad of an IPv4-mapped IPv6 address (`::ffff:a.b.c.d`), or null
     * for any other v6 address.
     */
    private static function mappedIpv4(string $ip): ?string
    {
        $bin = inet_pton($ip);

        if ($bin === false || strlen($bin) !== 16) {
            return null;
        }

        // First ten bytes zero, then 0xFFFF: the RFC 4291 mapped prefix.
        if (strncmp($bin, str_repeat("\0", 10)."\xFF\xFF", 12) !== 0) {
            return null;
        }

        return implode('.', array_map(ord(...), str_split(substr($bin, 12, 4))));
    }
}
