<?php

declare(strict_types=1);

namespace App\Domain\Ingest\Fetch;

/** Brings a remote feed into a local file: from the web, or from an FTP server. */
interface Fetcher
{
    /**
     * @param  string|null  $etag  and $lastModified from the last fetch: an unchanged feed is not downloaded again
     * @param  int|null  $maxBytes  the most this workspace may download (its plan); never above the server's cap
     * @param  Credentials|null  $credentials  the source's login: sent to the address's own origin and nowhere else
     *
     * @throws FetchFailed
     */
    public function fetch(string $url, string $destination, ?string $etag = null, ?string $lastModified = null, ?int $maxBytes = null, ?Credentials $credentials = null): FetchResult;
}
