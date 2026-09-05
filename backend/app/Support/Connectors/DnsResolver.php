<?php

namespace App\Support\Connectors;

/**
 * Resolves a hostname to its IP addresses (A + AAAA).
 *
 * The one seam OutboundHostPolicy exposes for testing. `dns_get_record`'s
 * availability and record shape differ between the Alpine image the connectors
 * run under and a developer's host, so the range test cannot drive real
 * resolution and assert on it; a fake bound here lets every range be exercised
 * deterministically.
 */
interface DnsResolver
{
    /**
     * Every address the host resolves to, IPv4 and IPv6 together.
     *
     * An empty list means the host does not resolve — the policy treats that as
     * Unreachable, never as allowed.
     *
     * @return list<string>
     */
    public function resolve(string $host): array;
}
