<?php

declare(strict_types=1);

namespace App\Domain\Ingest\Fetch;

interface Resolver
{
    /**
     * Every address the host name resolves to (IPv4 and IPv6); empty when it does not resolve.
     *
     * @return list<string>
     */
    public function resolve(string $host): array;
}
