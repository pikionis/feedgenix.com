<?php

declare(strict_types=1);

namespace App\Domain\Ingest\Fetch;

/** The system resolver: A and AAAA records. */
final class DnsResolver implements Resolver
{
    /** @return list<string> */
    public function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $addresses = [];
        set_error_handler(static fn (): bool => true); // a failed lookup is an empty answer, not a warning
        try {
            foreach ((array) dns_get_record($host, DNS_A | DNS_AAAA) as $record) {
                $address = $record['ip'] ?? $record['ipv6'] ?? null;
                if (is_string($address)) {
                    $addresses[] = $address;
                }
            }
        } finally {
            restore_error_handler();
        }

        return array_values(array_unique($addresses));
    }
}
