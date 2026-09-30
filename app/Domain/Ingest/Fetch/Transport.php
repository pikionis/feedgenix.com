<?php

declare(strict_types=1);

namespace App\Domain\Ingest\Fetch;

/**
 * One HTTP GET to an address that is already checked and pinned: the transport must connect to
 * $address and nowhere else (no second DNS lookup), follow no redirects, verify TLS, and stop
 * writing once more than $maxBytes arrived.
 */
interface Transport
{
    /**
     * @param  array<string, string>  $headers
     * @param  resource  $sink
     *
     * @throws FetchFailed for network, TLS, timeout and size failures
     */
    public function get(Target $target, string $address, array $headers, $sink, int $maxBytes, FetchSettings $settings): TransportResponse;
}
