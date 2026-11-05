<?php

declare(strict_types=1);

namespace App\Domain\Ingest\Fetch;

/**
 * Talks to a file server at an address that is already checked and pinned: it must connect to
 * $address and nowhere else, and stop writing once more than $maxBytes arrived.
 */
interface FileServer
{
    /** @throws FetchFailed */
    public function info(Target $target, string $address, ?Credentials $credentials, FetchSettings $settings): FileInfo;

    /**
     * @param  resource  $sink
     * @return int the bytes written
     *
     * @throws FetchFailed
     */
    public function download(Target $target, string $address, ?Credentials $credentials, $sink, int $maxBytes, FetchSettings $settings): int;
}
