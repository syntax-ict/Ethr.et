<?php

declare(strict_types=1);

namespace App\Services\Dns;

use App\Contracts\DnsResolver;

/**
 * PHP's own resolver: whatever the host's /etc/resolv.conf points at.
 *
 * `dns_get_record()` warns and returns false on a lookup failure (NXDOMAIN, a
 * timeout); both are read as "no record", which is what verification needs.
 */
final class SystemDnsResolver implements DnsResolver
{
    public function txt(string $name): array
    {
        $records = @dns_get_record($name, DNS_TXT);
        if (! is_array($records)) {
            return [];
        }

        $values = [];
        foreach ($records as $record) {
            // A TXT record longer than 255 bytes arrives split into `entries`;
            // `txt` is those joined, which is the value a person published.
            if (isset($record['txt']) && is_string($record['txt'])) {
                $values[] = $record['txt'];
            }
        }

        return $values;
    }

    public function cname(string $name): ?string
    {
        $records = @dns_get_record($name, DNS_CNAME);
        if (! is_array($records)) {
            return null;
        }

        foreach ($records as $record) {
            if (isset($record['target']) && is_string($record['target']) && $record['target'] !== '') {
                return rtrim(strtolower($record['target']), '.');
            }
        }

        return null;
    }
}
