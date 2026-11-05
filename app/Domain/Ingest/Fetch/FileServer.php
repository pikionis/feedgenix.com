<?php

declare(strict_types=1);

namespace App\Domain\Ingest\Fetch;

/**
 * Talks to a file server at an address that is already checked and pinned: it must connect to
 * $address and nowhere else, and stop writing once more than $maxBytes arrived. A server that proves
 * who it is by a key (SFTP) must match $hostKey, when there is one, before it is told anything.
 */
interface FileServer
{
    /** @throws FetchFailed */
    public function info(Target $target, string $address, ?Credentials $credentials, ?string $hostKey, FetchSettings $settings): FileInfo;

    /**
     * @param  resource  $sink
     * @return int the bytes written
     *
     * @throws FetchFailed
     */
    public function download(Target $target, string $address, ?Credentials $credentials, ?string $hostKey, $sink, int $maxBytes, FetchSettings $settings): int;
}
