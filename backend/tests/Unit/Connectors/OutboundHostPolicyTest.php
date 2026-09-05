<?php

use App\Support\Connectors\ConnectorException;
use App\Support\Connectors\ConnectorFailure;
use App\Support\Connectors\DnsResolver;
use App\Support\Connectors\OutboundHostPolicy;
use GuzzleHttp\Psr7\Uri;

/*
|--------------------------------------------------------------------------
| OutboundHostPolicy — the SSRF gate every tenant-supplied URL passes
|--------------------------------------------------------------------------
|
| The resolver is faked throughout. dns_get_record's behaviour differs between
| the Alpine image the connectors run under and a developer's host and cannot be
| asserted directly, so the range test drives resolution through the seam the
| policy exposes for exactly this reason. Literal-IP and literal-name cases never
| reach the resolver — the map they get is irrelevant to them.
|
*/

beforeEach(function () {
    // Names used by the resolve-path tests; anything absent resolves to nothing.
    OutboundHostPolicy::resolveUsing(new class implements DnsResolver
    {
        public function resolve(string $host): array
        {
            return match ($host) {
                'public.example.test' => ['93.184.216.34'],
                'ipv6-public.example.test' => ['2606:4700:4700::1111'],
                'private-a.example.test' => ['10.0.0.5'],
                'private-aaaa.example.test' => ['fc00::1'],
                'mapped-private.example.test' => ['::ffff:127.0.0.1'],
                'mapped-public.example.test' => ['::ffff:8.8.8.8'],
                'mixed.example.test' => ['93.184.216.34', '10.0.0.5'],
                default => [],
            };
        }
    });
});

afterEach(fn () => OutboundHostPolicy::resolveUsing(null));

function ohpFailure(string|Uri $url): ?ConnectorFailure
{
    try {
        OutboundHostPolicy::assertAllowed($url);

        return null;
    } catch (ConnectorException $e) {
        return $e->failure();
    }
}

/*
|--------------------------------------------------------------------------
| Scheme
|--------------------------------------------------------------------------
*/

it('refuses anything that is not https', function (string $url) {
    expect(ohpFailure($url))->toBe(ConnectorFailure::Misconfigured);
})->with([
    'plain http' => ['http://public.example.test/'],
    'http to a public ip' => ['http://8.8.8.8/'],
    'a file url' => ['file:///etc/passwd'],
    'a gopher url' => ['gopher://127.0.0.1:6379/'],
    'not a url at all' => ['public.example.test'],
]);

/*
|--------------------------------------------------------------------------
| Blocked host names — refused before any resolution
|--------------------------------------------------------------------------
*/

it('refuses an internal host name', function (string $url) {
    expect(ohpFailure($url))->toBe(ConnectorFailure::Misconfigured);
})->with([
    'localhost' => ['https://localhost/'],
    'localhost with a port' => ['https://localhost:8443/x'],
    'a .localhost subdomain' => ['https://api.localhost/'],
    'a .internal host' => ['https://db.internal/'],
    'a .local host' => ['https://printer.local/'],
    'gce metadata' => ['https://metadata.google.internal/computeMetadata/v1/'],
]);

/*
|--------------------------------------------------------------------------
| Blocked IP-literal ranges — no resolution, the literal is the target
|--------------------------------------------------------------------------
*/

it('refuses an https URL whose host is a literal address in a blocked range', function (string $ip) {
    expect(ohpFailure("https://{$ip}/"))->toBe(ConnectorFailure::Misconfigured);
})->with([
    // IPv4
    '0/8' => ['0.1.2.3'],
    '10/8' => ['10.11.12.13'],
    '100.64/10 low' => ['100.64.0.1'],
    '100.64/10 high' => ['100.127.255.255'],
    '127/8' => ['127.0.0.1'],
    '169.254/16 (cloud metadata)' => ['169.254.169.254'],
    '172.16/12 low' => ['172.16.0.1'],
    '172.16/12 high' => ['172.31.255.255'],
    '192.0.0/24' => ['192.0.0.10'],
    '192.168/16' => ['192.168.1.1'],
    '198.18/15 low' => ['198.18.0.1'],
    '198.18/15 high' => ['198.19.255.255'],
    '224/4 (multicast)' => ['224.0.0.1'],
    '239 (multicast high)' => ['239.255.255.255'],
    '240/4 (reserved)' => ['240.0.0.1'],
    '255 (broadcast)' => ['255.255.255.255'],
    // IPv6 (bracketed in the authority)
    ':: (unspecified)' => ['[::]'],
    '::1 (loopback)' => ['[::1]'],
    'fc00::/7 low' => ['[fc00::1]'],
    'fc00::/7 high' => ['[fdff::1]'],
    'fe80::/10 (link-local)' => ['[fe80::1]'],
    '64:ff9b::/96 (NAT64)' => ['[64:ff9b::102:304]'],
    'mapped loopback' => ['[::ffff:127.0.0.1]'],
    'mapped private' => ['[::ffff:10.0.0.1]'],
]);

/*
|--------------------------------------------------------------------------
| Allowed — public literals and public boundaries
|--------------------------------------------------------------------------
*/

it('allows an https URL whose host is a public literal address', function (string $ip) {
    expect(ohpFailure("https://{$ip}/"))->toBeNull();
})->with([
    'a public v4' => ['8.8.8.8'],
    'another public v4' => ['93.184.216.34'],
    'just below 100.64/10' => ['100.63.255.255'],
    'just below 172.16/12' => ['172.15.255.255'],
    'just above 172.16/12' => ['172.32.0.1'],
    'just below 198.18/15' => ['198.17.255.255'],
    'just above 198.18/15' => ['198.20.0.1'],
    'just below 224/4' => ['223.255.255.255'],
    'a public v6' => ['[2606:4700:4700::1111]'],
    'a mapped public v4' => ['[::ffff:8.8.8.8]'],
]);

/*
|--------------------------------------------------------------------------
| Resolution — a name is judged by where it points
|--------------------------------------------------------------------------
*/

it('allows a name that resolves entirely to public addresses', function () {
    expect(ohpFailure('https://public.example.test/path'))->toBeNull()
        ->and(ohpFailure('https://ipv6-public.example.test/'))->toBeNull()
        ->and(ohpFailure('https://mapped-public.example.test/'))->toBeNull();
});

it('refuses a name that resolves to a private address', function (string $host) {
    expect(ohpFailure("https://{$host}/"))->toBe(ConnectorFailure::Misconfigured);
})->with([
    'v4 private' => ['private-a.example.test'],
    'v6 ula' => ['private-aaaa.example.test'],
    'mapped private' => ['mapped-private.example.test'],
]);

it('refuses a name where even one of several addresses is internal', function () {
    // Every resolved address must pass: a public A record does not excuse a
    // private one on the same host.
    expect(ohpFailure('https://mixed.example.test/'))->toBe(ConnectorFailure::Misconfigured);
});

it('treats a name that does not resolve as Unreachable, not allowed', function () {
    $failure = ohpFailure('https://nothing-here.example.test/');

    expect($failure)->toBe(ConnectorFailure::Unreachable)
        ->and($failure->isTransient())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Accepts a PSR-7 UriInterface (the shape on_redirect hands it)
|--------------------------------------------------------------------------
*/

it('accepts a UriInterface, as the redirect hook passes', function () {
    expect(ohpFailure(new Uri('https://169.254.169.254/latest/')))->toBe(ConnectorFailure::Misconfigured)
        ->and(ohpFailure(new Uri('https://public.example.test/')))->toBeNull();
});

/*
|--------------------------------------------------------------------------
| redirectOptions — the block installed on the connectors' client
|--------------------------------------------------------------------------
*/

it('caps redirects, allows only https, and checks each hop', function () {
    $options = OutboundHostPolicy::redirectOptions();

    expect($options['max'])->toBe(3)
        ->and($options['protocols'])->toBe(['https'])
        ->and($options['on_redirect'])->toBeCallable();

    // The hook runs assertAllowed on the hop target (Guzzle passes it as the
    // third argument): an internal target throws, a public one does not.
    $onRedirect = $options['on_redirect'];

    expect(fn () => $onRedirect(null, null, new Uri('https://127.0.0.1/')))
        ->toThrow(ConnectorException::class);

    // A public hop target passes the hook without throwing.
    $onRedirect(null, null, new Uri('https://public.example.test/'));

    expect(true)->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| isHttpsUrl — the no-network scheme check the constructors and form reuse
|--------------------------------------------------------------------------
*/

it('recognises a well-formed https url without touching the network', function () {
    expect(OutboundHostPolicy::isHttpsUrl('https://example.com/x'))->toBeTrue()
        ->and(OutboundHostPolicy::isHttpsUrl('http://example.com/'))->toBeFalse()
        ->and(OutboundHostPolicy::isHttpsUrl('ftp://example.com/'))->toBeFalse()
        ->and(OutboundHostPolicy::isHttpsUrl('https://'))->toBeFalse()
        ->and(OutboundHostPolicy::isHttpsUrl('not a url'))->toBeFalse();
});
