<?php

declare(strict_types=1);

namespace App\Domain\Ingest\Fetch;

/** Brings a remote feed into a local file. HTTP(S) now; SFTP and FTP(S) arrive in M8. */
interface Fetcher
{
    /**
     * @param  string|null  $etag  and $lastModified from the last fetch: an unchanged feed answers 304
     *
     * @throws FetchFailed
     */
    public function fetch(string $url, string $destination, ?string $etag = null, ?string $lastModified = null): FetchResult;
}
