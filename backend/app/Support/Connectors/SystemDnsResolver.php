<?php

namespace App\Support\Connectors;

/**
 * The production resolver: `dns_get_record` for A and AAAA.
 *
 * The only DnsResolver used outside tests. It is deliberately tolerant of a
 * lookup that errors — a failed record type yields nothing rather than throwing,
 * and the policy reads an empty result as Unreachable — so a transient resolver
 * hiccup does not surface as a different failure class than an unresolvable host.
 */
final class SystemDnsResolver implements DnsResolver
{
    public function resolve(string $host): array
    {
        $addresses = [];

        foreach ([DNS_A => 'ip', DNS_AAAA => 'ipv6'] as $type => $field) {
            $records = @dns_get_record($host, $type);

            if ($records === false) {
                continue;
            }

            foreach ($records as $record) {
                if (isset($record[$field]) && is_string($record[$field]) && $record[$field] !== '') {
                    $addresses[] = $record[$field];
                }
            }
        }

        return $addresses;
    }
}
