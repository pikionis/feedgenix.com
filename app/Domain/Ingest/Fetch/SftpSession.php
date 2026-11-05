<?php

declare(strict_types=1);

namespace App\Domain\Ingest\Fetch;

use Closure;

/** One connection to an SFTP server: the seam around the SSH library. */
interface SftpSession
{
    /**
     * The key the server proved it holds, as `ssh-ed25519 AAAA…`; null when its signature does not
     * check out. This must not send anything of the customer's.
     *
     * @throws FetchFailed
     */
    public function hostKey(): ?string;

    /** @throws FetchFailed */
    public function login(Credentials $credentials): bool;

    /**
     * @return array{size: ?int, modified_at: ?int, directory: bool}
     *
     * @throws FetchFailed
     */
    public function stat(string $path): array;

    /**
     * Streams a file: at most $maxBytes are asked of the server, and none of it is kept in memory.
     *
     * @param  Closure(string): void  $chunk
     *
     * @throws FetchFailed
     */
    public function read(string $path, Closure $chunk, int $maxBytes): void;
}
